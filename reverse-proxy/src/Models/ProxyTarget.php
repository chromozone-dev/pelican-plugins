<?php

namespace Chromozone\ReverseProxy\Models;

use Chromozone\ReverseProxy\Contracts\ProxyDriver;
use Chromozone\ReverseProxy\Drivers\NpmFamilyDriver;
use Chromozone\ReverseProxy\Enums\ProxyVariant;
use Exception;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A proxy manager instance this panel can drive.
 *
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string $base_url
 * @property string $identity
 * @property string $secret
 * @property bool $verify_tls
 * @property string|null $variant
 * @property bool $is_default
 */
class ProxyTarget extends Model implements HasLabel
{
    protected $fillable = [
        'name',
        'driver',
        'base_url',
        'identity',
        'secret',
        'verify_tls',
        'variant',
        'is_default',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest: NPM has no long-lived API keys, so this is a
            // real account password.
            'secret' => 'encrypted',
            'verify_tls' => 'bool',
            'is_default' => 'bool',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $target) {
            // Credentials or address changed, so any cached session belongs to
            // the old ones. Without this a rotated password appears not to take
            // effect until the cached token expires, which can be ~23 hours.
            if ($target->wasChanged(['base_url', 'identity', 'secret', 'verify_tls'])) {
                Cache::forget($target->sessionCacheKey());
                Cache::forget($target->sessionCacheKey() . ':failed');
            }

            if (!$target->is_default) {
                return;
            }

            // Query-builder update, so this does not re-fire model events.
            static::query()
                ->whereKeyNot($target->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });

        // The FK cascade would delete this target's domains and their routes
        // directly in the database, firing no model events - leaving every proxy
        // host live in the proxy manager AND destroying the credentials needed to
        // ever reach it again. Deleting through the models first is what makes
        // this recoverable.
        static::deleting(function (self $target) {
            foreach ($target->domains as $domain) {
                try {
                    $domain->delete();
                } catch (Exception $exception) {
                    report($exception);
                }
            }
        });
    }

    public function sessionCacheKey(): string
    {
        return 'reverse-proxy:session:' . $this->id;
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ProxyDomain::class, 'target_id');
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function variantEnum(): ?ProxyVariant
    {
        return blank($this->variant) ? null : ProxyVariant::tryFrom($this->variant);
    }

    /**
     * Named resolveDriver() rather than driver() so it cannot be confused with
     * the `driver` column.
     */
    public function resolveDriver(): ProxyDriver
    {
        return match ($this->driver) {
            default => new NpmFamilyDriver($this),
        };
    }

    public static function defaultTarget(): ?self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->first();
    }
}
