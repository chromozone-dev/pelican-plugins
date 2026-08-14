<?php

namespace Chromozone\ReverseProxy\Services;

use App\Models\Allocation;
use Chromozone\ReverseProxy\Exceptions\ProxyDriverException;
use Chromozone\ReverseProxy\Models\ProxyRoute;

/**
 * Works out the address the proxy manager should dial to reach a server's port.
 *
 * This is not simply the allocation IP: Pelican allocations very often bind
 * 0.0.0.0, which is meaningless as a forwarding destination.
 */
class ForwardHostResolver
{
    /** @throws ProxyDriverException */
    public function resolve(ProxyRoute $route): string
    {
        return $this->resolveForAllocation($route->allocation);
    }

    /**
     * Takes the allocation directly so the destination can be shown in the create
     * form, before any route exists to resolve from.
     *
     * @throws ProxyDriverException
     */
    public function resolveForAllocation(Allocation $allocation): string
    {
        $node = $allocation->node;

        // Admin override wins - the proxy may reach nodes on an address that
        // differs from anything the panel stores.
        $override = $node->proxy_forward_host; // @phpstan-ignore property.notFound

        if (filled($override)) {
            return $override;
        }

        // The address the service is actually bound to. Deliberately preferred
        // over the allocation's alias: the alias is a display value shown to
        // players, typically a public DNS name, and dialling it sends traffic out
        // to the internet and back in, which only works if the network does NAT
        // hairpinning. A proxy alongside the node wants the node's own address.
        if (!$this->isBindAll($allocation->ip)) {
            return $allocation->ip;
        }

        // A bind-all allocation is reachable at the node's own address, so the
        // FQDN is the correct destination here rather than an error. The alias is
        // not consulted at all - where it would genuinely be the right target,
        // the node override says so explicitly instead of guessing.
        if (filled($node->fqdn)) {
            return $node->fqdn;
        }

        throw new ProxyDriverException(sprintf(
            'Cannot determine where the proxy should forward to: port %d binds every interface and node "%s" has no FQDN. Set a proxy forward host on that node.',
            $allocation->port,
            $node->name,
        ));
    }

    private function isBindAll(string $ip): bool
    {
        return in_array(trim($ip, '[]'), ['0.0.0.0', '::'], true);
    }
}
