<?php

namespace Chromozone\ReverseProxy\Models;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A port that is published on the proxy manager's container and therefore usable
 * as a stream's incoming port.
 *
 * This exists because Docker cannot add ports to a running container: the set of
 * usable ports is fixed when the proxy manager is created, so an admin declares
 * them here and routes claim from that pool. Inventing a port the container does
 * not publish would produce a stream that silently never accepts a connection.
 *
 * @property int $id
 * @property int $target_id
 * @property int $port
 * @property bool $tcp
 * @property bool $udp
 * @property string|null $label
 * @property ProxyTarget $target
 * @property ProxyRoute|null $route
 */
class ProxyStreamPort extends Model implements HasLabel
{
    protected $fillable = [
        'target_id',
        'port',
        'tcp',
        'udp',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'port' => 'integer',
            'tcp' => 'bool',
            'udp' => 'bool',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ProxyTarget::class, 'target_id');
    }

    /** At most one, enforced by a unique index: two routes on one port would shadow each other. */
    public function route(): HasOne
    {
        return $this->hasOne(ProxyRoute::class, 'stream_port_id');
    }

    public function getLabel(): string
    {
        $protocols = implode('/', array_filter([
            $this->tcp ? 'TCP' : null,
            $this->udp ? 'UDP' : null,
        ]));

        return trim($this->port . ' ' . $protocols . ($this->label ? ' - ' . $this->label : ''));
    }

    public function isClaimed(): bool
    {
        return $this->route()->exists();
    }
}
