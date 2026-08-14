<?php

/**
 * The address the proxy manager is told to connect to. Getting this wrong is not
 * a visible error - the proxy entry is created happily and simply never reaches
 * the server - so the precedence is pinned here.
 */

namespace App\Tests\Unit\ReverseProxy;

use App\Models\Allocation;
use App\Models\Node;
use App\Tests\TestCase;
use Chromozone\ReverseProxy\Exceptions\ProxyDriverException;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Services\ForwardHostResolver;

class ForwardHostResolverTest extends TestCase
{
    /**
     * The regression that prompted this: an allocation alias is a display value
     * shown to players, usually a public DNS name. Dialling it sends traffic out
     * to the internet and back, which only works with NAT hairpinning - so a
     * proxy on the same network could not reach the server at all.
     */
    public function test_bound_ip_is_preferred_over_the_allocation_alias(): void
    {
        $route = $this->route(ip: '10.10.10.42', alias: 'ddns.example.com', fqdn: 'node1.example.com');

        $this->assertSame('10.10.10.42', $this->resolve($route));
    }

    public function test_node_override_wins_over_everything(): void
    {
        $route = $this->route(ip: '10.10.10.42', alias: 'ddns.example.com', fqdn: 'node1.example.com', override: '172.16.0.9');

        $this->assertSame('172.16.0.9', $this->resolve($route));
    }

    /** A bind-all allocation is reachable at the node's own address. */
    public function test_bind_all_falls_back_to_the_node_fqdn(): void
    {
        $this->assertSame('node1.example.com', $this->resolve(
            $this->route(ip: '0.0.0.0', alias: 'ddns.example.com', fqdn: 'node1.example.com'),
        ));

        $this->assertSame('node1.example.com', $this->resolve(
            $this->route(ip: '::', alias: null, fqdn: 'node1.example.com'),
        ));
    }

    public function test_bind_all_without_an_fqdn_is_an_error_naming_the_fix(): void
    {
        $this->expectException(ProxyDriverException::class);
        $this->expectExceptionMessageMatches('/proxy forward host/i');

        $this->resolve($this->route(ip: '0.0.0.0', alias: null, fqdn: ''));
    }

    private function resolve(ProxyRoute $route): string
    {
        return (new ForwardHostResolver())->resolve($route);
    }

    private function route(string $ip, ?string $alias, string $fqdn, ?string $override = null): ProxyRoute
    {
        // Attributes are set directly rather than mass-assigned: Node and
        // Allocation both use $guarded, which consults the database schema.
        $node = new Node();
        $node->name = 'node1';
        $node->fqdn = $fqdn;
        $node->proxy_forward_host = $override; // @phpstan-ignore property.notFound

        $allocation = new Allocation();
        $allocation->ip = $ip;
        $allocation->ip_alias = $alias;
        $allocation->port = 9020;
        $allocation->setRelation('node', $node);

        $route = new ProxyRoute();
        $route->server_id = 1;
        $route->setRelation('allocation', $allocation);

        return $route;
    }
}
