<?php

namespace Chromozone\SftpHelper\Providers;

use App\Enums\HeaderWidgetPosition;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use Chromozone\SftpHelper\Filament\Server\Widgets\SftpConnectionWidget;
use Illuminate\Support\ServiceProvider;

class SftpHelperPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ListFiles::getDefaultHeaderWidgets() is empty, so Before vs. After only
        // matters relative to other plugins that also hook this page - either way
        // this widget renders above the file table itself.
        ListFiles::registerCustomHeaderWidgets(HeaderWidgetPosition::Before, SftpConnectionWidget::class);
    }
}
