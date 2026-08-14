<?php

namespace Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyTargets\Pages;

use Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyTargets\ProxyTargetResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Exists so the stream port pool has somewhere to live: relation managers need a
 * record page, and the target list is otherwise managed entirely in modals.
 */
class EditProxyTarget extends EditRecord
{
    protected static string $resource = ProxyTargetResource::class;
}
