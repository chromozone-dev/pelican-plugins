<?php

namespace Chromozone\ReverseProxy\Enums;

use Filament\Support\Contracts\HasLabel;

enum RouteType: string implements HasLabel
{
    case Http = 'http';

    /**
     * Raw TCP/UDP forwarding, for game protocols that carry no hostname the way
     * HTTP carries a Host header. The proxy listens on a port and forwards it,
     * which is what lets a server on a non-default port be reached by name at
     * the game's default port - one server per port, since nginx stream cannot
     * distinguish hostnames.
     */
    case Stream = 'stream';

    public function getLabel(): string
    {
        return match ($this) {
            self::Http => 'HTTP(S)',
            self::Stream => 'TCP/UDP stream',
        };
    }

    public function isStream(): bool
    {
        return $this === self::Stream;
    }
}
