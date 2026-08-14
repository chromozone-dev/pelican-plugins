<?php

/**
 * Tests for the one piece of this plugin that is genuinely non-obvious: NPM and
 * NPMplus authenticate in incompatible ways, and the driver has to detect which
 * it is talking to from the shape of the token response alone.
 *
 * The panel owns the test harness, so these run from a panel checkout. Copy
 * `tests/Unit/.` from this plugin into the panel's `tests/Unit/` - the directory
 * layout here already matches the App\Tests\Unit\* namespace PHPUnit expects -
 * then:
 *
 *     vendor/bin/phpunit --testsuite Unit --filter NpmFamilyDriver
 */

namespace App\Tests\Unit\ReverseProxy;

use App\Enums\ServerState;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Tests\TestCase;
use Chromozone\ReverseProxy\Drivers\NpmFamilyDriver;
use Chromozone\ReverseProxy\Enums\RouteType;
use Chromozone\ReverseProxy\Exceptions\ProxyDriverException;
use Chromozone\ReverseProxy\Models\ProxyDomain;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Models\ProxyStreamPort;
use Chromozone\ReverseProxy\Models\ProxyTarget;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use ReflectionClass;

class NpmFamilyDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // In a real install PluginService registers this namespace when the
        // plugin loads. Unit tests bypass plugin loading entirely, so without
        // this every trans('reverse-proxy::…') call returns its own key and any
        // assertion on user-facing wording would be meaningless.
        Lang::addNamespace('reverse-proxy', base_path('plugins/reverse-proxy/lang'));
    }

    private function target(bool $verifyTls = false): ProxyTarget
    {
        $target = new ProxyTarget([
            'name' => 'Test',
            'driver' => 'npm',
            'base_url' => 'https://proxy.example.com:81',
            'identity' => 'service@example.com',
            'secret' => 'hunter2',
            'verify_tls' => $verifyTls,
        ]);

        // Unsaved on purpose: none of the paths exercised here touch the database.
        $target->id = 1;

        return $target;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function tokenResponse(array $body, array $headers = []): PromiseInterface
    {
        return Http::response($body, 200, $headers);
    }

    /**
     * Upstream Nginx Proxy Manager: JWT arrives in the body and goes back as a
     * bearer header.
     */
    public function test_upstream_npm_uses_bearer_auth(): void
    {
        Http::fake([
            '*/api/tokens' => $this->tokenResponse([
                'expires' => now()->addDay()->toIso8601String(),
                'token' => 'jwt-abc123',
            ]),
            '*/api/nginx/certificates' => Http::response([
                [
                    'id' => 7,
                    'nice_name' => 'example.com',
                    'domain_names' => ['example.com', '*.example.com'],
                    'expires_on' => '2026-12-01T00:00:00.000Z',
                ],
            ]),
        ]);

        $certificates = (new NpmFamilyDriver($this->target()))->listCertificates();

        $this->assertCount(1, $certificates);
        $this->assertSame(7, $certificates[0]['id']);
        $this->assertSame(['example.com', '*.example.com'], $certificates[0]['domain_names']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/nginx/certificates')
            && $request->hasHeader('Authorization', 'Bearer jwt-abc123'));
    }

    /**
     * NPMplus: the token is stripped from the body and delivered only as a
     * signed __Host-Http-token cookie, which we must replay verbatim.
     */
    public function test_npmplus_uses_cookie_auth(): void
    {
        Http::fake([
            '*/api/tokens' => $this->tokenResponse(
                ['expires' => now()->addDay()->toIso8601String()],
                ['Set-Cookie' => '__Host-Http-token=s%3Ajwt-abc123.sig; Path=/; HttpOnly; Secure; SameSite=Strict'],
            ),
            '*/api/nginx/certificates' => Http::response([]),
        ]);

        (new NpmFamilyDriver($this->target()))->listCertificates();

        Http::assertSent(function (Request $request) {
            if (!str_contains($request->url(), '/api/nginx/certificates')) {
                return false;
            }

            // No bearer header exists to send, and NPMplus would ignore one anyway.
            return !$request->hasHeader('Authorization')
                && str_contains($request->header('Cookie')[0] ?? '', '__Host-Http-token=');
        });
    }

    /**
     * The failure mode that would otherwise look like bad credentials: NPMplus
     * over plain http never issues its Secure cookie, so nothing comes back.
     */
    public function test_missing_token_and_cookie_explains_the_https_requirement(): void
    {
        Http::fake([
            '*/api/tokens' => $this->tokenResponse(['expires' => now()->addDay()->toIso8601String()]),
        ]);

        $this->expectException(ProxyDriverException::class);
        $this->expectExceptionMessageMatches('/https/');

        (new NpmFamilyDriver($this->target()))->listCertificates();
    }

    /**
     * NPMplus rate-limits failed logins to 5 per 5 minutes, so re-authenticating
     * on every call would be a good way to lock the service account out.
     */
    public function test_session_is_cached_between_calls(): void
    {
        Http::fake([
            '*/api/tokens' => $this->tokenResponse([
                'expires' => now()->addDay()->toIso8601String(),
                'token' => 'jwt-abc123',
            ]),
            '*/api/nginx/certificates' => Http::response([]),
        ]);

        $driver = new NpmFamilyDriver($this->target());
        $driver->listCertificates();
        $driver->listCertificates();

        $tokenCalls = 0;
        Http::assertSent(function (Request $request) use (&$tokenCalls) {
            if (str_ends_with($request->url(), '/api/tokens')) {
                $tokenCalls++;
            }

            return true;
        });

        $this->assertSame(1, $tokenCalls, 'the driver should authenticate once and reuse the cached session');
    }

    /**
     * The first thing anyone hits: NPMplus serves its admin interface with a
     * self-signed certificate, so cURL refuses it and the raw error says nothing
     * about the toggle that fixes it.
     */
    public function test_a_self_signed_certificate_failure_explains_the_fix(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 60: SSL certificate problem: self-signed certificate',
        ));

        $this->expectException(ProxyDriverException::class);
        $this->expectExceptionMessageMatches('/Verify TLS certificate/');

        (new NpmFamilyDriver($this->target(verifyTls: true)))->listCertificates();
    }

    /** The hint would be wrong advice when verification is already disabled. */
    public function test_no_tls_hint_when_verification_is_already_off(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 60: SSL certificate problem: self-signed certificate',
        ));

        try {
            (new NpmFamilyDriver($this->target(verifyTls: false)))->listCertificates();
            $this->fail('expected the driver to throw');
        } catch (ProxyDriverException $exception) {
            $this->assertStringNotContainsString('Verify TLS certificate', $exception->getMessage());
        }
    }

    /**
     * Suspending a server has to take its hostname off the air. Blocking the
     * panel page is not enough - the proxy entry keeps serving until it is
     * published as disabled.
     */
    public function test_a_suspended_server_is_published_as_disabled(): void
    {
        $this->assertSame(false, $this->publishedEnabledFlag(suspended: true));
    }

    public function test_a_running_server_is_published_as_enabled(): void
    {
        $this->assertSame(true, $this->publishedEnabledFlag(suspended: false));
    }

    private function publishedEnabledFlag(bool $suspended): ?bool
    {
        Http::fake([
            '*/api/tokens' => $this->tokenResponse([
                'expires' => now()->addDay()->toIso8601String(),
                'token' => 'jwt-abc123',
            ]),
            '*/api/nginx/proxy-hosts' => Http::response(['id' => 12]),
        ]);

        (new NpmFamilyDriver($this->target()))->upsertRoute($this->route($suspended));

        $enabled = null;

        Http::assertSent(function (Request $request) use (&$enabled) {
            if (str_ends_with($request->url(), '/api/nginx/proxy-hosts')) {
                $enabled = $request->data()['enabled'] ?? null;
            }

            return true;
        });

        return $enabled;
    }

    private function route(bool $suspended, bool $stream = false): ProxyRoute
    {
        // Built entirely from unsaved models with relations set by hand, so this
        // needs no database. Attributes are assigned directly because Node,
        // Allocation and Server all use $guarded, which consults the schema.
        $node = new Node();
        $node->name = 'node1';
        $node->fqdn = 'node1.example.com';

        $allocation = new Allocation();
        $allocation->ip = '10.0.0.5';
        $allocation->port = 9020;
        $allocation->server_id = 1;
        $allocation->setRelation('node', $node);

        $server = new Server();
        $server->uuid = 'a-server-uuid';
        $server->status = $suspended ? ServerState::Suspended : null;

        $domain = new ProxyDomain(['name' => 'example.com', 'certificate_id' => 7, 'force_ssl' => true]);
        $domain->setRelation('target', $this->target());

        $route = new ProxyRoute();
        $route->label = 'map';
        $route->forward_scheme = 'http';
        $route->websockets = true;
        $route->block_exploits = true;
        $route->server_id = 1;
        $route->allocation_id = 1;
        $route->type = $stream ? RouteType::Stream : RouteType::Http;
        $route->setRelation('allocation', $allocation);
        $route->setRelation('server', $server);
        $route->setRelation('domain', $domain);

        if ($stream) {
            // 25565 on the proxy, while the server itself is on 9020 above.
            $port = new ProxyStreamPort(['port' => 25565, 'tcp' => true, 'udp' => false]);
            $route->stream_tcp = true;
            $route->stream_udp = false;
            $route->setRelation('streamPort', $port);
        }

        return $route;
    }

    /**
     * The point of a stream: incoming_port need not equal forwarding_port, so a
     * server on a non-default port becomes reachable by name at the game's
     * default port, with no SRV record and no port for the player to type.
     */
    public function test_a_stream_listens_on_the_pool_port_and_forwards_to_the_server_port(): void
    {
        Http::fake([
            '*/api/tokens' => $this->tokenResponse([
                'expires' => now()->addDay()->toIso8601String(),
                'token' => 'jwt-abc123',
            ]),
            '*/api/nginx/streams' => Http::response(['id' => 5]),
        ]);

        $route = $this->route(suspended: false, stream: true);

        $id = (new NpmFamilyDriver($this->target()))->upsertRoute($route);

        $this->assertSame('5', $id);

        Http::assertSent(function (Request $request) {
            if (!str_ends_with($request->url(), '/api/nginx/streams')) {
                return false;
            }

            $data = $request->data();

            return $data['incoming_port'] === 25565      // the game's default, on the proxy
                && $data['forwarding_port'] === 9020     // where the server actually listens
                && $data['forwarding_host'] === '10.0.0.5'
                && $data['tcp_forwarding'] === true
                && $data['udp_forwarding'] === false
                // Fields the proxy-host endpoint takes must not leak in: both
                // forks declare additionalProperties:false on streams too.
                && !array_key_exists('domain_names', $data)
                && !array_key_exists('forward_scheme', $data)
                && !array_key_exists('certificate_id', $data);
        });
    }

    /** A stream must go to the streams endpoint, never the proxy-host one. */
    public function test_a_stream_does_not_touch_the_proxy_host_endpoint(): void
    {
        Http::fake([
            '*/api/tokens' => $this->tokenResponse([
                'expires' => now()->addDay()->toIso8601String(),
                'token' => 'jwt-abc123',
            ]),
            '*/api/nginx/streams' => Http::response(['id' => 5]),
        ]);

        (new NpmFamilyDriver($this->target()))->upsertRoute($this->route(suspended: false, stream: true));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'proxy-hosts'));
    }

    /**
     * Both forks declare additionalProperties:false on the proxy-host endpoints,
     * so every field we send has to be accepted by both. Adding a fork-specific
     * field here (access_list_id, npmplus_*) is a 400 against the other one.
     */
    public function test_payload_fields_are_accepted_by_both_forks(): void
    {
        // Taken from backend/schema/paths/nginx/proxy-hosts/post.json in each repo.
        $acceptedByNpm = [
            'access_list_id', 'advanced_config', 'allow_websocket_upgrade', 'block_exploits',
            'caching_enabled', 'certificate_id', 'domain_names', 'enabled', 'forward_host',
            'forward_port', 'forward_scheme', 'hsts_enabled', 'hsts_subdomains', 'http2_support',
            'locations', 'meta', 'ssl_forced', 'trust_forwarded_proto',
        ];

        $acceptedByNpmPlus = [
            'advanced_config', 'allow_websocket_upgrade', 'block_exploits', 'caching_enabled',
            'certificate_id', 'domain_names', 'enabled', 'forward_host', 'forward_port',
            'forward_scheme', 'hsts_enabled', 'hsts_subdomains', 'http2_support', 'locations',
            'meta', 'npmplus_access_list_ids', 'npmplus_access_list_type', 'ssl_forced',
            'trust_forwarded_proto',
        ];

        /** @var string[] $shared */
        $shared = (new ReflectionClass(NpmFamilyDriver::class))->getConstant('SHARED_FIELDS');

        $this->assertNotEmpty($shared);

        foreach ($shared as $field) {
            $this->assertContains($field, $acceptedByNpm, "[$field] is rejected by Nginx Proxy Manager");
            $this->assertContains($field, $acceptedByNpmPlus, "[$field] is rejected by NPMplus");
        }
    }
}
