<?php

namespace Chromozone\ReverseProxy\Models;

use App\Facades\Activity;
use App\Models\Allocation;
use App\Models\Server;
use Chromozone\ReverseProxy\Enums\RouteType;
use Chromozone\ReverseProxy\Exceptions\ProxyDriverException;
use Chromozone\ReverseProxy\Services\ForwardHostResolver;
use Exception;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One hostname pointing at one allocation.
 *
 * @property int $id
 * @property int $server_id
 * @property int $allocation_id
 * @property int $domain_id
 * @property string $label
 * @property RouteType $type
 * @property string $forward_scheme
 * @property string|null $external_id
 * @property bool $websockets
 * @property bool $block_exploits
 * @property int|null $stream_port_id
 * @property bool $stream_tcp
 * @property bool $stream_udp
 * @property ProxyStreamPort|null $streamPort
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property-read string $hostname
 * @property-read string $url
 * @property ProxyDomain $domain
 * @property Allocation $allocation
 * @property Server $server
 */
class ProxyRoute extends Model implements HasLabel
{
    protected $fillable = [
        'server_id',
        'allocation_id',
        'domain_id',
        'label',
        'type',
        'forward_scheme',
        'external_id',
        'websockets',
        'block_exploits',
        'stream_port_id',
        'stream_tcp',
        'stream_udp',
        // Written via updateQuietly() after a sync attempt, so they must be
        // mass-assignable or the values are silently dropped.
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'server_id' => 'integer',
            'allocation_id' => 'integer',
            'domain_id' => 'integer',
            'type' => RouteType::class,
            'websockets' => 'bool',
            'block_exploits' => 'bool',
            'stream_port_id' => 'integer',
            'stream_tcp' => 'bool',
            'stream_udp' => 'bool',
            'last_synced_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        // Fires after the local row is gone. If the proxy manager is unreachable
        // the exception surfaces to the caller and `reconcile --prune` can clean
        // up the leftover entry later - we deliberately do not block deletion of
        // a local record on a third-party service being up.
        static::deleted(function (self $route) {
            // Logged before the remote call so the record survives even if the
            // proxy manager is unreachable - the local deletion already happened.
            Activity::event('server:proxy-route.delete')
                ->subject($route, $route->server)
                ->property('hostname', $route->hostname)
                ->log();

            $route->deleteFromProxy();
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ProxyDomain::class, 'domain_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function streamPort(): BelongsTo
    {
        return $this->belongsTo(ProxyStreamPort::class, 'stream_port_id');
    }

    /** @return Attribute<string, string> */
    protected function label(): Attribute
    {
        return Attribute::make(
            // Hostnames are case-insensitive, but the unique index is not under
            // SQLite (Pelican's default) or PostgreSQL. Without normalising on
            // write, "Shop" and "shop" both pass validation and both get pushed,
            // and nginx lowercases server_name so one silently shadows the other.
            set: fn (string $value) => Str::lower($value),
        );
    }

    /** @return Attribute<string, never> */
    protected function hostname(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->label . '.' . $this->domain->name,
        );
    }

    /** @return Attribute<string, never> */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => (filled($this->domain->certificate_id) ? 'https://' : 'http://') . $this->hostname,
        );
    }

    public function getLabel(): string
    {
        return $this->hostname;
    }

    /**
     * Has this route's allocation stopped belonging to this route's server?
     *
     * Core detaches an allocation from a server with a query-builder update
     * (`Allocation::where(...)->update(['server_id' => null])`), which fires no
     * model events and leaves the row intact - so neither the cascade FK nor any
     * observer removes this route. The freed port can then be handed to a
     * different server, at which point this hostname would publish somebody
     * else's service.
     */
    public function isDetached(): bool
    {
        return $this->allocation->server_id !== $this->server_id;
    }

    /**
     * Where the proxy manager is told to connect, as a displayable string. The
     * single most useful thing to show when a proxy resolves but does not work.
     */
    public function forwardTarget(): string
    {
        $host = app(ForwardHostResolver::class)->resolve($this);

        if ($this->type->isStream()) {
            $protocols = implode('/', array_filter([
                $this->stream_tcp ? 'tcp' : null,
                $this->stream_udp ? 'udp' : null,
            ]));

            return sprintf('%s %s:%d', $protocols, $host, $this->allocation->port);
        }

        return sprintf('%s://%s:%d', $this->forward_scheme, $host, $this->allocation->port);
    }

    /**
     * What a player actually connects to. For a stream this is the hostname plus
     * the proxy's incoming port, which is the whole point: the port shown here is
     * the game's default even when the server itself runs on another one.
     */
    public function publicAddress(): string
    {
        if ($this->type->isStream()) {
            return $this->hostname . ':' . ($this->streamPort->port ?? 0);
        }

        return $this->url;
    }

    /** @throws Exception */
    public function syncToProxy(): void
    {
        // Checked here rather than in the service because this is the only choke
        // point every remote write passes through, including reconciliation -
        // otherwise a scheduled --repair would keep re-affirming a stale entry.
        if ($this->isDetached()) {
            throw new ProxyDriverException(
                trans('reverse-proxy::strings.errors.allocation_detached', ['hostname' => $this->hostname]),
            );
        }

        $externalId = $this->domain->target->resolveDriver()->upsertRoute($this);

        $this->updateQuietly([
            'external_id' => $externalId,
            'last_synced_at' => now(),
            'last_error' => null,
        ]);
    }

    /** @throws Exception */
    public function deleteFromProxy(): void
    {
        $this->domain->target->resolveDriver()->deleteRoute($this);
    }
}
