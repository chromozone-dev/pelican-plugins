<?php

namespace Chromozone\ReverseProxy\Services;

use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Models\ProxyTarget;
use Exception;

/**
 * The proxy manager is external state that humans also edit by hand, so drift is
 * a matter of when, not if. Compares what we think exists against what actually
 * does, touching only entries carrying our meta stamp for this panel.
 */
class ReconcileService
{
    public function __construct(private readonly ForwardHostResolver $forwardHosts) {}

    /**
     * @return array{detached: list<string>, missing: list<string>, drifted: list<string>, duplicated: list<string>, orphaned: list<string>, foreign: list<string>, repaired: list<string>, pruned: list<string>, errors: list<string>}
     *
     * @throws Exception
     */
    public function reconcile(ProxyTarget $target, bool $repair = false, bool $prune = false): array
    {
        $driver = $target->resolveDriver();

        $report = [
            'detached' => [],
            'missing' => [],
            'drifted' => [],
            'duplicated' => [],
            'orphaned' => [],
            'foreign' => [],
            'repaired' => [],
            'pruned' => [],
            'errors' => [],
        ];

        // Every stamped entry on the instance, including any belonging to a
        // different panel that shares this proxy manager.
        $stamped = collect($driver->listManagedRoutes());
        $panel = (string) config('reverse-proxy.panel_id');

        $foreign = $stamped->filter(fn (array $entry) => $entry['panel'] !== $panel);

        foreach ($foreign as $entry) {
            $report['foreign'][] = $this->describe($entry);
        }

        // Only entries this panel wrote are ever considered for repair or prune.
        $ours = $stamped->reject(fn (array $entry) => $entry['panel'] !== $panel);

        $byRoute = $ours->groupBy('route_id');

        $local = ProxyRoute::query()
            ->whereHas('domain', fn ($query) => $query->where('target_id', $target->id))
            ->with(['domain.target', 'allocation.node', 'server'])
            ->get();

        foreach ($local as $route) {
            /** @var list<array<string, mixed>> $entries */
            $entries = ($byRoute->get($route->id) ?? collect())->values()->all();

            // Handled before anything else: a detached route's allocation may now
            // belong to a different server, so its hostname could be publishing
            // somebody else's service. Comparing forwarding values cannot catch
            // this, because both sides are derived from the same allocation row.
            // The remedy is removal, not repair.
            if ($route->isDetached()) {
                $report['detached'][] = $route->hostname;

                if ($repair) {
                    $this->attempt($report, $route->hostname, function () use ($route, &$report) {
                        $route->delete();
                        $report['repaired'][] = $route->hostname;
                    });
                }

                continue;
            }

            if ($entries === []) {
                $report['missing'][] = $route->hostname;

                if ($repair) {
                    $this->attempt($report, $route->hostname, function () use ($route, &$report) {
                        $route->syncToProxy();
                        $report['repaired'][] = $route->hostname;
                    });
                }

                continue;
            }

            // More than one remote entry claims this route. Reachable when a
            // create succeeded remotely but its response never arrived, so the
            // local row kept a stale external_id and the next sync created a
            // second entry. nginx then has two conflicting server_name blocks.
            if (count($entries) > 1) {
                $extras = $this->extraEntries($route, $entries);

                $report['duplicated'][] = $route->hostname . ' (' . (count($entries)) . ' entries)';

                if ($prune) {
                    foreach ($extras as $extra) {
                        $this->attempt($report, $route->hostname, function () use ($driver, $extra, $route, &$report) {
                            $driver->deleteExternal((string) $extra['external_id']);
                            $report['pruned'][] = $route->hostname . ' #' . $extra['external_id'];
                        });
                    }
                }
            }

            $drift = $this->drift($route, $this->preferredEntry($route, $entries));

            if ($drift === []) {
                continue;
            }

            $report['drifted'][] = $route->hostname . ' (' . implode('; ', $drift) . ')';

            if ($repair) {
                $this->attempt($report, $route->hostname, function () use ($route, &$report) {
                    $route->syncToProxy();
                    $report['repaired'][] = $route->hostname;
                });
            }
        }

        // Compared against every local route, not just this target's: two targets
        // can point at the same proxy manager, and scoping this to one of them
        // would classify the other's entries as orphans and prune them.
        $allLocalIds = ProxyRoute::query()->pluck('id')->all();

        foreach ($ours as $entry) {
            if (in_array($entry['route_id'], $allLocalIds, true)) {
                continue;
            }

            $label = $this->describe($entry);
            $report['orphaned'][] = $label;

            if ($prune) {
                $this->attempt($report, $label, function () use ($driver, $entry, $label, &$report) {
                    $driver->deleteExternal((string) $entry['external_id']);
                    $report['pruned'][] = $label;
                });
            }
        }

        return $report;
    }

    /**
     * @param  array<string, list<string>>  $report
     * @param  callable(): void  $callback
     */
    private function attempt(array &$report, string $label, callable $callback): void
    {
        try {
            $callback();
        } catch (Exception $exception) {
            $report['errors'][] = $label . ': ' . $exception->getMessage();
        }
    }

    /**
     * The entry the local row believes in, so drift is measured against the one
     * that would actually be updated.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function preferredEntry(ProxyRoute $route, array $entries): array
    {
        foreach ($entries as $entry) {
            if ((string) $entry['external_id'] === (string) $route->external_id) {
                return $entry;
            }
        }

        return $entries[0];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function extraEntries(ProxyRoute $route, array $entries): array
    {
        $keep = $this->preferredEntry($route, $entries);

        return array_values(array_filter(
            $entries,
            fn (array $entry) => (string) $entry['external_id'] !== (string) $keep['external_id'],
        ));
    }

    /** @param  array<string, mixed>  $entry */
    private function describe(array $entry): string
    {
        $names = $entry['domain_names'] ?: ['proxy host #' . $entry['external_id']];

        return implode(', ', $names);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function drift(ProxyRoute $route, array $entry): array
    {
        try {
            $expectedHost = $this->forwardHosts->resolve($route);
        } catch (Exception $exception) {
            return ['cannot resolve forward host: ' . $exception->getMessage()];
        }

        $drift = [];

        if ($entry['forward_host'] !== $expectedHost) {
            $drift[] = sprintf('forward host is %s, expected %s', $entry['forward_host'] ?? 'unset', $expectedHost);
        }

        if ($entry['forward_port'] !== $route->allocation->port) {
            $drift[] = sprintf('forward port is %s, expected %d', $entry['forward_port'] ?? 'unset', $route->allocation->port);
        }

        if ($entry['forward_scheme'] !== $route->forward_scheme) {
            $drift[] = sprintf('scheme is %s, expected %s', $entry['forward_scheme'] ?? 'unset', $route->forward_scheme);
        }

        // Compared unconditionally, including the 0 case. Skipping the comparison
        // when no certificate is configured would make clearing a domain's
        // certificate undetectable as well as unfixable.
        $expectedCertificate = (int) ($route->domain->certificate_id ?? 0);

        if ($entry['certificate_id'] !== $expectedCertificate) {
            $drift[] = sprintf('certificate is %s, expected %d', $entry['certificate_id'], $expectedCertificate);
        }

        $expectedSsl = $expectedCertificate > 0 && $route->domain->force_ssl;

        if ($entry['ssl_forced'] !== $expectedSsl) {
            $drift[] = sprintf('forced SSL is %s, expected %s', var_export($entry['ssl_forced'], true), var_export($expectedSsl, true));
        }

        // Expected state rather than a fixed "should be enabled": a suspended
        // server's entry is supposed to be off, and repair must not switch it
        // back on.
        $expectedEnabled = !$route->server->isSuspended();

        if ($entry['enabled'] !== $expectedEnabled) {
            $drift[] = $expectedEnabled
                ? 'entry is disabled in the proxy manager'
                : 'entry is still enabled although the server is suspended';
        }

        $hostnames = array_map('strtolower', $entry['domain_names'] ?? []);

        if (!in_array(strtolower($route->hostname), $hostnames, true)) {
            $drift[] = 'hostname is missing from the proxy entry';
        }

        return $drift;
    }
}
