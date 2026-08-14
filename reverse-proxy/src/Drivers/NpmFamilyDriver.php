<?php

namespace Chromozone\ReverseProxy\Drivers;

use Carbon\CarbonImmutable;
use Chromozone\ReverseProxy\Contracts\ProxyDriver;
use Chromozone\ReverseProxy\Enums\AuthMode;
use Chromozone\ReverseProxy\Enums\ProxyVariant;
use Chromozone\ReverseProxy\Exceptions\ProxyDriverException;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Models\ProxyTarget;
use Chromozone\ReverseProxy\Services\ForwardHostResolver;
use Chromozone\ReverseProxy\Support\DriverStatus;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Driver for Nginx Proxy Manager and its NPMplus fork.
 *
 * One driver covers both because their proxy-host endpoints require an identical
 * core payload. They differ in two ways that matter here:
 *
 *  1. Authentication. NPM returns the JWT in the POST /api/tokens body and takes
 *     it back as a bearer header. NPMplus strips the token from the body and
 *     only sets a signed, Secure `__Host-Http-token` cookie, with no bearer
 *     fallback - so its base URL must be https and we must replay the cookie.
 *     The token response shape is what we use to tell the two apart.
 *
 *  2. Extra fields. NPMplus renamed access lists and added npmplus_* options.
 *     Both schemas are additionalProperties:false, so we send only the shared
 *     subset (see SHARED_FIELDS) and keep fork-specific options out entirely.
 */
class NpmFamilyDriver implements ProxyDriver
{
    /**
     * Fields accepted by proxy-host create AND update on both forks. Sending
     * anything outside this list is a 400 on one fork or the other.
     */
    private const SHARED_FIELDS = [
        'domain_names',
        'forward_scheme',
        'forward_host',
        'forward_port',
        'certificate_id',
        'ssl_forced',
        'http2_support',
        'hsts_enabled',
        'hsts_subdomains',
        'allow_websocket_upgrade',
        'block_exploits',
        'caching_enabled',
        'advanced_config',
        'locations',
        'meta',
        'enabled',
    ];

    public function __construct(private readonly ProxyTarget $target) {}

    public function testConnection(): DriverStatus
    {
        $this->forgetSession();

        $health = $this->attempt(fn () => $this->rawClient()->get('api/'));

        if (!$health->successful()) {
            throw new ProxyDriverException($this->errorMessage($health, 'The proxy manager health check failed'));
        }

        $session = $this->authenticate();
        $authMode = AuthMode::from($session['mode']);

        // The auth transport is the reliable signal. The health payload's
        // `version` key corroborates it: NPM includes it, NPMplus omits it.
        $variant = $authMode === AuthMode::Cookie ? ProxyVariant::NpmPlus : ProxyVariant::Npm;

        $this->target->update(['variant' => $variant->value]);

        return new DriverStatus(
            variant: $variant,
            authMode: $authMode,
            version: $this->formatVersion($health->json('version')),
            certificateCount: count($this->listCertificates()),
        );
    }

    public function listCertificates(): array
    {
        $response = $this->request('get', 'api/nginx/certificates');

        if (!$response->successful()) {
            throw new ProxyDriverException($this->errorMessage($response, 'Could not list certificates'));
        }

        /** @var array<int, array<string, mixed>> $certificates */
        $certificates = $response->json() ?? [];

        return collect($certificates)
            ->map(fn (array $certificate) => [
                'id' => (int) $certificate['id'],
                'nice_name' => $certificate['nice_name'] ?? ('#' . $certificate['id']),
                'domain_names' => $certificate['domain_names'] ?? [],
                'expires_on' => $certificate['expires_on'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function upsertRoute(ProxyRoute $route): string
    {
        $payload = $this->buildPayload($route);

        if (filled($route->external_id)) {
            $existing = $this->request('get', 'api/nginx/proxy-hosts/' . $route->external_id);

            if ($existing->successful()) {
                // The proxy manager writes its own keys into meta (nginx_online,
                // nginx_err), so merge rather than replace.
                $payload['meta'] = array_merge($existing->json('meta') ?? [], $payload['meta']);

                $response = $this->request('put', 'api/nginx/proxy-hosts/' . $route->external_id, $payload);

                if (!$response->successful()) {
                    throw new ProxyDriverException($this->errorMessage($response, 'Could not update the proxy host'));
                }

                return (string) ($response->json('id') ?? $route->external_id);
            }

            if ($existing->status() !== 404) {
                throw new ProxyDriverException($this->errorMessage($existing, 'Could not read the existing proxy host'));
            }

            // Deleted in the proxy manager behind our back - fall through and recreate.
        }

        $response = $this->request('post', 'api/nginx/proxy-hosts', $payload);

        if (!$response->successful()) {
            throw new ProxyDriverException($this->errorMessage($response, 'Could not create the proxy host'));
        }

        $id = $response->json('id');

        if (blank($id)) {
            throw new ProxyDriverException('The proxy manager accepted the proxy host but returned no id.');
        }

        return (string) $id;
    }

    public function deleteRoute(ProxyRoute $route): void
    {
        if (blank($route->external_id)) {
            return;
        }

        $this->deleteExternal($route->external_id);
    }

    public function deleteExternal(string $externalId): void
    {
        $response = $this->request('delete', 'api/nginx/proxy-hosts/' . $externalId);

        // Already gone is the outcome we wanted.
        if ($response->successful() || $response->status() === 404) {
            return;
        }

        throw new ProxyDriverException($this->errorMessage($response, 'Could not delete the proxy host'));
    }

    public function listManagedRoutes(): array
    {
        $response = $this->request('get', 'api/nginx/proxy-hosts');

        if (!$response->successful()) {
            throw new ProxyDriverException($this->errorMessage($response, 'Could not list proxy hosts'));
        }

        /** @var array<int, array<string, mixed>> $hosts */
        $hosts = $response->json() ?? [];

        return collect($hosts)
            ->filter(fn (array $host) => filled(data_get($host, 'meta.pelican.route_id')))
            ->map(fn (array $host) => [
                'external_id' => (string) $host['id'],
                'route_id' => (int) data_get($host, 'meta.pelican.route_id'),
                'panel' => (string) (data_get($host, 'meta.pelican.panel') ?? ''),
                'domain_names' => $host['domain_names'] ?? [],
                'forward_host' => $host['forward_host'] ?? null,
                'forward_port' => isset($host['forward_port']) ? (int) $host['forward_port'] : null,
                'forward_scheme' => $host['forward_scheme'] ?? null,
                'certificate_id' => isset($host['certificate_id']) ? (int) $host['certificate_id'] : 0,
                'ssl_forced' => (bool) ($host['ssl_forced'] ?? false),
                'enabled' => (bool) ($host['enabled'] ?? true),
            ])
            ->values()
            ->all();
    }

    public function capabilities(): array
    {
        $isPlus = $this->target->variant === ProxyVariant::NpmPlus->value;

        return [
            // Named differently per fork; unused in v1 but this is where a future
            // access-list feature branches.
            'access_list_field' => $isPlus ? 'npmplus_access_list_ids' : 'access_list_id',
            'http3' => $isPlus,
            'stream_sni' => !$isPlus,
        ];
    }

    /** @return array<string, mixed> */
    private function buildPayload(ProxyRoute $route): array
    {
        $domain = $route->domain;

        $payload = [
            'domain_names' => [$route->hostname],
            'forward_scheme' => $route->forward_scheme,
            'forward_host' => app(ForwardHostResolver::class)->resolve($route),
            'forward_port' => $route->allocation->port,
            'allow_websocket_upgrade' => (bool) $route->websockets,
            'block_exploits' => (bool) $route->block_exploits,
            'caching_enabled' => false,
            'http2_support' => true,
            'hsts_enabled' => false,
            'hsts_subdomains' => false,
            'advanced_config' => '',
            'locations' => [],
            // A suspended server must stop being publicly reachable. Blocking the
            // panel page is not enough - the proxy entry keeps serving until it
            // is disabled here.
            'enabled' => !$route->server->isSuspended(),
            // Sent unconditionally. Omitting them when a domain's certificate is
            // cleared would leave the proxy manager holding the old certificate
            // and ssl_forced, with nothing to indicate the mismatch. 0 is NPM's
            // "no certificate", and forcing SSL without one is meaningless.
            'certificate_id' => (int) ($domain->certificate_id ?? 0),
            'ssl_forced' => filled($domain->certificate_id) && $domain->force_ssl,
            // Lets reconciliation tell our entries apart from hand-made ones, from
            // other targets, and from another panel sharing this proxy manager.
            'meta' => [
                'pelican' => [
                    'managed_by' => 'pelican-reverse-proxy',
                    'panel' => (string) config('reverse-proxy.panel_id'),
                    'route_id' => $route->id,
                    'server_uuid' => $route->server->uuid,
                    'allocation_id' => $route->allocation_id,
                ],
            ],
        ];

        return Arr::only($payload, self::SHARED_FIELDS);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ProxyDriverException
     */
    private function request(string $method, string $uri, array $payload = []): Response
    {
        $send = function () use ($method, $uri, $payload): Response {
            $client = $this->client();

            return match ($method) {
                'get' => $client->get($uri),
                'post' => $client->post($uri, $payload),
                'put' => $client->put($uri, $payload),
                'delete' => $client->delete($uri),
                default => throw new ProxyDriverException("Unsupported HTTP method [$method]."),
            };
        };

        $response = $this->attempt($send);

        // Cached session rejected (expired, or invalidated server-side). Drop it
        // and let the next call mint a fresh one, exactly once.
        if (in_array($response->status(), [401, 403], true)) {
            $this->forgetSession();

            $response = $this->attempt($send);
        }

        return $response;
    }

    /**
     * @param  callable(): Response  $callback
     *
     * @throws ProxyDriverException
     */
    private function attempt(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException $exception) {
            throw new ProxyDriverException($this->connectionMessage($exception), previous: $exception);
        }
    }

    private function connectionMessage(ConnectionException $exception): string
    {
        $message = sprintf('Could not reach the proxy manager at %s: %s', $this->target->base_url, $exception->getMessage());

        // Far and away the most common first-run failure: NPMplus serves its
        // admin interface with a self-signed certificate, and reaching it on a
        // LAN address means no certificate could ever validate. cURL's own text
        // describes the problem without hinting at the one-toggle fix.
        if ($this->target->verify_tls && $this->isTlsTrustFailure($exception->getMessage())) {
            $message .= ' ' . trans('reverse-proxy::strings.errors.tls_trust');
        }

        return $message;
    }

    private function isTlsTrustFailure(string $message): bool
    {
        $message = strtolower($message);

        foreach ([
            'ssl certificate problem',
            'self-signed',
            'self signed',
            'unable to get local issuer',
            'certificate verify failed',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function client(): PendingRequest
    {
        $session = $this->session();
        $client = $this->rawClient();

        if ($session['mode'] === AuthMode::Bearer->value) {
            return $client->withToken($session['token']);
        }

        // The cookie is signed by Express, so it only has to be replayed
        // verbatim - the jar handles that without us parsing anything.
        return $client->withOptions([
            'cookies' => new CookieJar(false, $session['cookies'] ?? []),
        ]);
    }

    private function rawClient(): PendingRequest
    {
        $client = Http::acceptJson()
            ->asJson()
            ->baseUrl(rtrim($this->target->base_url, '/'))
            ->timeout((int) config('reverse-proxy.timeout', 10))
            ->connectTimeout((int) config('reverse-proxy.connect_timeout', 3));

        // NPMplus serves its admin API over https on :81 with a self-signed
        // certificate, which is the common case for a LAN address.
        if (!$this->target->verify_tls) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * @return array{mode: string, token: string|null, cookies: array<mixed>|null, expires: string|null}
     *
     * @throws ProxyDriverException
     */
    private function session(): array
    {
        $session = Cache::get($this->target->sessionCacheKey());

        if (is_array($session)) {
            return $session;
        }

        // Fail fast on a recent auth failure instead of retrying. Every Livewire
        // round trip on the admin domain form re-reads the certificate list, and
        // NPMplus rate-limits failed /tokens to 5 per 5 minutes - without this,
        // filling in that form with a wrong password locks the service account
        // out, which also breaks route creation for users.
        $failure = Cache::get($this->failureCacheKey());

        if (is_string($failure)) {
            throw new ProxyDriverException($failure);
        }

        return $this->authenticate();
    }

    /**
     * @return array{mode: string, token: string|null, cookies: array<mixed>|null, expires: string|null}
     *
     * @throws ProxyDriverException
     */
    private function authenticate(): array
    {
        $jar = new CookieJar();

        $response = $this->attempt(fn () => $this->rawClient()
            ->withOptions(['cookies' => $jar])
            ->post('api/tokens', [
                'identity' => $this->target->identity,
                'secret' => $this->target->secret,
            ]));

        if (!$response->successful()) {
            throw new ProxyDriverException($this->rememberFailure(
                $this->errorMessage($response, 'Authentication with the proxy manager failed'),
            ));
        }

        $body = $response->json() ?? [];

        if (($body['requiresTotp'] ?? false) === true) {
            throw new ProxyDriverException($this->rememberFailure(
                'This proxy manager account has two-factor authentication enabled, which cannot be automated. Use a dedicated service account without 2FA.',
            ));
        }

        if (filled($body['token'] ?? null)) {
            $session = [
                'mode' => AuthMode::Bearer->value,
                'token' => $body['token'],
                'cookies' => null,
                'expires' => $body['expires'] ?? null,
            ];
        } else {
            $cookies = $jar->toArray();

            if ($cookies === []) {
                throw new ProxyDriverException($this->rememberFailure('The proxy manager returned neither a token nor a session cookie. If this is NPMplus, check that the base URL starts with https:// - its session cookie is marked Secure and is never issued over plain HTTP.'));
            }

            $session = [
                'mode' => AuthMode::Cookie->value,
                'token' => null,
                'cookies' => $cookies,
                'expires' => $body['expires'] ?? null,
            ];
        }

        Cache::forget($this->failureCacheKey());
        $this->cacheSession($session);

        return $session;
    }

    /**
     * Records an auth failure so the next call fails immediately rather than
     * spending another attempt against a rate limiter. Returns the message so
     * callers can throw it in one expression.
     */
    private function rememberFailure(string $message): string
    {
        Cache::put($this->failureCacheKey(), $message, 60);

        return $message;
    }

    /**
     * External id of the entry stamped for this route, if the proxy manager has
     * one. Used to clean up after a create whose response never arrived.
     *
     * @throws ProxyDriverException
     */
    public function findExternalIdForRoute(int $routeId): ?string
    {
        foreach ($this->listManagedRoutes() as $entry) {
            if ($entry['route_id'] === $routeId) {
                return (string) $entry['external_id'];
            }
        }

        return null;
    }

    /** @param  array{mode: string, token: string|null, cookies: array<mixed>|null, expires: string|null}  $session */
    private function cacheSession(array $session): void
    {
        // Renew five minutes early. NPMplus rate-limits failed logins hard
        // (5 per 5 minutes), so re-authenticating on every call is a bad idea.
        $ttl = 300;

        if (filled($session['expires'])) {
            try {
                $secondsLeft = (int) now()->diffInSeconds(CarbonImmutable::parse($session['expires']));
                $ttl = max(60, $secondsLeft - 300);
            } catch (Throwable) {
                // Unparseable expiry - keep the conservative fallback.
            }
        }

        Cache::put($this->target->sessionCacheKey(), $session, $ttl);
    }

    private function forgetSession(): void
    {
        Cache::forget($this->target->sessionCacheKey());
        Cache::forget($this->failureCacheKey());
    }

    private function failureCacheKey(): string
    {
        return $this->target->sessionCacheKey() . ':failed';
    }

    private function formatVersion(mixed $version): ?string
    {
        if (!is_array($version)) {
            return null;
        }

        return implode('.', [
            $version['major'] ?? 0,
            $version['minor'] ?? 0,
            $version['revision'] ?? 0,
        ]);
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        $message = $response->json('error.message') ?? $response->json('message');

        return blank($message)
            ? sprintf('%s (HTTP %d)', $fallback, $response->status())
            : sprintf('%s: %s', $fallback, is_string($message) ? $message : json_encode($message));
    }
}
