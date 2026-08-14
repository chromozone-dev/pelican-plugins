<?php

namespace Chromozone\ReverseProxy\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProxyVariant: string implements HasLabel
{
    case Npm = 'npm';
    case NpmPlus = 'npmplus';

    public function getLabel(): string
    {
        return match ($this) {
            self::Npm => 'Nginx Proxy Manager',
            self::NpmPlus => 'NPMplus',
        };
    }
}
