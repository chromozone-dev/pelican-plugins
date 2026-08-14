<?php

namespace Chromozone\ReverseProxy\Services;

use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use Chromozone\ReverseProxy\Models\ProxyDomain;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Exception;
use Illuminate\Support\Facades\DB;

class ProxyRouteService
{
    /**
     * Persist a route and push it to the proxy manager.
     *
     * Every guard is re-checked here rather than trusted from the form, because
     * this is the only path that writes routes. `$asUser` covers the rules that
     * apply to self-service only - admins are not subject to the per-server quota
     * and may use domains withheld from users.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Exception
     */
    public function handle(array $data, ?ProxyRoute $route = null, bool $asUser = true): ProxyRoute
    {
        return is_null($route)
            ? $this->create($data, $asUser)
            : $this->update($route, $data, $asUser);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Exception
     */
    private function create(array $data, bool $asUser): ProxyRoute
    {
        $serverId = (int) ($data['server_id'] ?? 0);

        $this->assertAllocationBelongsToServer((int) ($data['allocation_id'] ?? 0), $serverId);

        if ($asUser) {
            $this->assertDomainIsUserFacing((int) ($data['domain_id'] ?? 0));
            $this->assertWithinQuota($serverId);
        }

        $route = ProxyRoute::create($data);
        $route->refresh();
        $route->loadMissing(['domain.target', 'allocation.node', 'server']);

        try {
            $route->syncToProxy();
        } catch (Exception $exception) {
            $this->rollBackCreate($route);

            throw $exception;
        }

        $this->log($route, 'create');

        return $route;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Exception
     */
    private function update(ProxyRoute $route, array $data, bool $asUser): ProxyRoute
    {
        $this->assertAllocationBelongsToServer(
            (int) ($data['allocation_id'] ?? $route->allocation_id),
            $route->server_id,
        );

        if ($asUser) {
            $this->assertDomainIsUserFacing((int) ($data['domain_id'] ?? $route->domain_id));
        }

        try {
            // The local change commits only once the proxy manager has accepted
            // it. Without this, a failed sync left the panel advertising a
            // hostname that was never published, next to a sync timestamp from
            // the previous, successful write. The remote call runs inside the
            // transaction, which is acceptable here: these are single, interactive
            // writes, not a hot path.
            DB::transaction(function () use ($route, $data) {
                $route->update($data);
                $route->refresh();
                $route->loadMissing(['domain.target', 'allocation.node', 'server']);
                $route->syncToProxy();
            });
        } catch (Exception $exception) {
            // Written after the rollback so the note survives it.
            $route->refresh();
            $route->updateQuietly(['last_error' => $exception->getMessage()]);

            throw $exception;
        }

        $this->log($route, 'update');

        return $route;
    }

    /**
     * A create can fail after the proxy manager has accepted the entry - a
     * response that timed out, or one carrying no id. Remove any entry stamped
     * for this route before dropping the local row, so it is not stranded.
     */
    private function rollBackCreate(ProxyRoute $route): void
    {
        try {
            $driver = $route->domain->target->resolveDriver();
            $externalId = $route->external_id ?: $driver->findExternalIdForRoute($route->id);

            if (filled($externalId)) {
                $driver->deleteExternal((string) $externalId);
            }
        } catch (Exception $exception) {
            // Best effort - reconcile --prune is the backstop.
            report($exception);
        }

        // Quietly, so the delete hook does not fire a second remote delete.
        $route->deleteQuietly();
    }

    private function log(ProxyRoute $route, string $event): void
    {
        // A subuser can publish a hostname pointing at the server, so this needs
        // to be visible in the server's activity log. The server is passed as an
        // explicit subject because ActivityLogService only infers it from the
        // Filament tenant, which is not a Server on the admin panel path.
        Activity::event("server:proxy-route.$event")
            ->subject($route, $route->server)
            ->property('hostname', $route->hostname)
            ->property('port', $route->allocation->port)
            ->log();
    }

    /** @throws Exception */
    private function assertAllocationBelongsToServer(int $allocationId, int $serverId): void
    {
        $allocation = Allocation::query()->find($allocationId);

        if (is_null($allocation) || $allocation->server_id !== $serverId) {
            throw new Exception(trans('reverse-proxy::strings.errors.allocation_mismatch'));
        }
    }

    /**
     * Enforced here as well as in the form. The form's Select restricts the
     * options, and Filament turns that into an implicit `in` rule, but that is an
     * accident of the UI rather than a guarantee - it disappears the moment the
     * field is changed or a non-Filament caller is added.
     *
     * @throws Exception
     */
    private function assertDomainIsUserFacing(int $domainId): void
    {
        $domain = ProxyDomain::query()->find($domainId);

        if (is_null($domain) || !$domain->allow_user_routes) {
            throw new Exception(trans('reverse-proxy::strings.errors.domain_not_available'));
        }
    }

    /** @throws Exception */
    private function assertWithinQuota(int $serverId): void
    {
        /** @var Server $server */
        $server = Server::query()->findOrFail($serverId);

        $limit = (int) ($server->proxy_route_limit ?? 0);
        $used = ProxyRoute::query()->where('server_id', $serverId)->count();

        if ($used >= $limit) {
            throw new Exception(trans('reverse-proxy::strings.limit_reached'));
        }
    }
}
