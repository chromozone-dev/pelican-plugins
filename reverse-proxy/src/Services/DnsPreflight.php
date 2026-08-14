<?php

namespace Chromozone\ReverseProxy\Services;

/**
 * A proxy entry does nothing until DNS points the hostname at the proxy. Rather
 * than silently depending on a wildcard record existing, warn when it doesn't -
 * this turns the most common "my proxy doesn't work" into a self-explaining
 * message. Never blocks creation; DNS may propagate later.
 */
class DnsPreflight
{
    public function resolves(string $hostname): bool
    {
        if (!config('reverse-proxy.dns_preflight', true)) {
            return true;
        }

        foreach (['A', 'AAAA', 'CNAME'] as $type) {
            if (@checkdnsrr($hostname, $type)) {
                return true;
            }
        }

        return false;
    }
}
