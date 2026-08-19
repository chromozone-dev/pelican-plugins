<?php

namespace Chromozone\SftpHelper\Filament\Server\Widgets;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Filament\Components\Actions\ConnectSftpAction;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Throwable;

/**
 * Reuses the same fields, labels and hint action as the "SFTP Information"
 * fieldset on the server Settings page (Settings::form()), but surfaces them
 * on the Files page instead - where a user actually wants that information
 * when they're about to move files around.
 */
class SftpConnectionWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static bool $isLazy = false;

    protected string $view = 'sftp-helper::widgets.sftp-connection';

    protected int|string|array $columnSpan = 'full';

    public ?Server $server = null;

    /**
     * The folder currently being browsed, or null at the root. ListFiles is
     * registered against '/{path?}' (FileResource::getPages()) and relies on
     * Livewire's own route-parameter-to-property binding for its $path - we
     * have no override point to have it hand this to us directly, so we read
     * the same request route parameter it was bound from. Falls back to null
     * (root) if that parameter is ever renamed, rather than erroring.
     */
    public ?string $currentPath = null;

    /**
     * Backing state for the schema below - every field needs a real
     * statePath to write to (Filament derives one from each field's own
     * name, e.g. 'data.connection'). Mirrors ServerFormPage's own
     * `public ?array $data = []` + `statePath('data')`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        try {
            $server = Filament::getTenant();
            $this->server = $server instanceof Server ? $server : null;

            $path = request()->route('path');
            $this->currentPath = filled($path) ? (string) $path : null;

            // Nothing calls Schema::fill() for us here - a Page gets it for
            // free from its own mount() (see ServerFormPage::fillForm()), but
            // a bare widget doesn't. Without it, hydrateState() never runs,
            // so afterStateHydrated() (what formatStateUsing()/default() rely
            // on to actually write a value) never fires - every field renders
            // with no error and no value, forever. This is that missing call.
            $this->getSchema('form')?->fill();
        } catch (Throwable $exception) {
            // Never let a mistake in here take the Files page down with it -
            // this widget is a convenience, not something the whole page's
            // availability should depend on. Falls back to rendering nothing
            // (see form() below) rather than a 500.
            report($exception);

            $this->server = null;
            $this->currentPath = null;
        }
    }

    public static function canView(): bool
    {
        try {
            $server = Filament::getTenant();

            return ($server instanceof Server) && (bool) user()?->can(SubuserPermission::FileSftp, $server);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function form(Schema $schema): Schema
    {
        $server = $this->server;
        $currentPath = $this->currentPath;

        if (!$server) {
            return $schema->components([]);
        }

        try {
            return $schema
                ->statePath('data')
                ->model($server)
                ->components([
                    Section::make(trans('server/setting.server_info.sftp.title'))
                        ->icon(TablerIcon::Plug)
                        ->iconColor('success')
                        ->collapsible()
                        ->persistCollapsed()
                        ->columns([
                            'default' => 1,
                            'sm' => 1,
                            'md' => 3,
                            'lg' => 3,
                        ])
                        ->schema([
                            // default(), not state()/formatStateUsing(): fill()
                            // above resets every field with no default() to
                            // null first (hydrateDefaultState()), then only
                            // restores it from getDefaultState() - the one
                            // mechanism that survives that reset for both a
                            // Field (TextInput) and an Entry (TextEntry).
                            TextInput::make('connection')
                                ->label(trans('server/setting.server_info.sftp.connection'))
                                ->columnSpan(1)
                                ->disabled()
                                ->dehydrated(false)
                                ->copyable()
                                ->hint(fn () => filled($currentPath) ? trans('sftp-helper::strings.connection_hint') : null)
                                ->hintAction(ConnectSftpAction::make('hint_connect_sftp')->directory($currentPath))
                                ->default(fn () => $server->getSftpUrl(directory: $currentPath)),
                            TextInput::make('username')
                                ->label(trans('server/setting.server_info.sftp.username'))
                                ->columnSpan(1)
                                ->disabled()
                                ->dehydrated(false)
                                ->copyable()
                                ->default(fn () => user()?->username . '.' . $server->uuid_short),
                            TextEntry::make('password')
                                ->label(trans('server/setting.server_info.sftp.password'))
                                ->columnSpan(1)
                                ->default(trans('server/setting.server_info.sftp.password_body')),
                        ]),
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return $schema->components([]);
        }
    }
}
