<?php

namespace Chromozone\ReverseProxy;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

class ReverseProxyPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'reverse-proxy';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Chromozone\\ReverseProxy\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Chromozone\\ReverseProxy\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'hostname_blacklist' => array_filter(array_map('trim', explode(',', (string) config('reverse-proxy.hostname_blacklist')))),
            'dns_preflight' => (bool) config('reverse-proxy.dns_preflight'),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            TagsInput::make('hostname_blacklist')
                ->label(trans('reverse-proxy::strings.hostname_blacklist'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('reverse-proxy::strings.hostname_blacklist_help'))
                ->default(fn () => array_filter(array_map('trim', explode(',', (string) config('reverse-proxy.hostname_blacklist'))))),
            Toggle::make('dns_preflight')
                ->label(trans('reverse-proxy::strings.dns_preflight'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('reverse-proxy::strings.dns_preflight_help'))
                ->default(fn () => (bool) config('reverse-proxy.dns_preflight')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'REVERSE_PROXY_HOSTNAME_BLACKLIST' => implode(',', $data['hostname_blacklist'] ?? []),
            'REVERSE_PROXY_DNS_PREFLIGHT' => ($data['dns_preflight'] ?? true) ? 'true' : 'false',
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
