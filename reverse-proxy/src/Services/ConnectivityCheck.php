<?php

namespace Chromozone\ReverseProxy\Services;

use Chromozone\ReverseProxy\Models\ProxyRoute;
use Exception;

/**
 * Answers "so why isn't this working?" for a route that exists but does not
 * serve. A proxy entry can be perfectly valid and still never reach anything.
 *
 * Important caveat, and it is stated in the UI too: this runs from the panel, so
 * it proves the panel can reach the destination. The proxy manager is a
 * different host. When both sit on the same network - the usual setup - it is a
 * good signal, not a guarantee.
 */
class ConnectivityCheck
{
    public function __construct(
        private readonly ForwardHostResolver $forwardHosts,
        private readonly DnsPreflight $dns,
    ) {}

    /**
     * @return array{target: string, reachable: bool, detail: string, dns: bool}
     */
    public function check(ProxyRoute $route, int $timeout = 3): array
    {
        try {
            $host = $this->forwardHosts->resolve($route);
        } catch (Exception $exception) {
            return [
                'target' => '-',
                'reachable' => false,
                'detail' => $exception->getMessage(),
                'dns' => $this->dns->resolves($route->hostname),
            ];
        }

        $port = $route->allocation->port;
        $target = $host . ':' . $port;

        // A plain TCP connect rather than an HTTP request: it is protocol
        // agnostic, so it works whether the service speaks HTTP, HTTPS or is
        // still starting up, and it cannot be confused by an application-level
        // error response.
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        if ($socket === false) {
            return [
                'target' => $target,
                'reachable' => false,
                'detail' => trim($errstr) !== '' ? "$errstr (errno $errno)" : "Could not connect (errno $errno)",
                'dns' => $this->dns->resolves($route->hostname),
            ];
        }

        fclose($socket);

        return [
            'target' => $target,
            'reachable' => true,
            'detail' => 'Connected',
            'dns' => $this->dns->resolves($route->hostname),
        ];
    }
}
