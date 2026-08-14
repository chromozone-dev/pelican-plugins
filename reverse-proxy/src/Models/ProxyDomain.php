<?php

namespace Chromozone\ReverseProxy\Models;

use Exception;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A domain an admin owns and is willing to hand out hostnames under.
 *
 * @property int $id
 * @property int $target_id
 * @property string $name
 * @property int|null $certificate_id
 * @property bool $force_ssl
 * @property bool $allow_user_routes
 * @property ProxyTarget $target
 */
class ProxyDomain extends Model implements HasLabel
{
    protected $fillable = [
        'target_id',
        'name',
        'certificate_id',
        'force_ssl',
        'allow_user_routes',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'certificate_id' => 'integer',
            'force_ssl' => 'bool',
            'allow_user_routes' => 'bool',
        ];
    }

    protected static function booted(): void
    {
        // Same reason as ProxyTarget: the FK cascade removes routes without
        // firing model events, so their entries would stay live in the proxy
        // manager with nothing left pointing at them.
        static::deleting(function (self $domain) {
            foreach ($domain->routes as $route) {
                try {
                    $route->delete();
                } catch (Exception $exception) {
                    report($exception);
                }
            }
        });
    }

    /** @return Attribute<string, string> */
    protected function name(): Attribute
    {
        return Attribute::make(
            // Same reasoning as ProxyRoute::label() - domains are case-insensitive
            // but the unique index is not on every supported database.
            set: fn (string $value) => Str::lower(trim($value)),
        );
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ProxyTarget::class, 'target_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(ProxyRoute::class, 'domain_id');
    }

    public function getLabel(): string
    {
        return $this->name;
    }
}
