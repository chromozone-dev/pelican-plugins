<?php

namespace Chromozone\ReverseProxy\Filament\Server\Resources\ProxyRoutes\Pages;

use Chromozone\ReverseProxy\Filament\Server\Resources\ProxyRoutes\ProxyRouteResource;
use Filament\Resources\Pages\ListRecords;

class ListProxyRoutes extends ListRecords
{
    protected static string $resource = ProxyRouteResource::class;

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
