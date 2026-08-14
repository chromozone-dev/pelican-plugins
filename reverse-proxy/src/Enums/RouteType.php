<?php

namespace Chromozone\ReverseProxy\Enums;

use Filament\Support\Contracts\HasLabel;

enum RouteType: string implements HasLabel
{
    case Http = 'http';

    public function getLabel(): string
    {
        return match ($this) {
            self::Http => 'HTTP(S)',
        };
    }
}
