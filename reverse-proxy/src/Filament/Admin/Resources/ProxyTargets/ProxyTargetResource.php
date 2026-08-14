<?php

namespace Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyTargets;

use Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyTargets\Pages\EditProxyTarget;
use Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyTargets\Pages\ManageProxyTargets;
use Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyTargets\RelationManagers\StreamPortRelationManager;
use Chromozone\ReverseProxy\Models\ProxyTarget;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProxyTargetResource extends Resource
{
    protected static ?string $model = ProxyTarget::class;

    protected static ?string $slug = 'proxy-targets';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-server-cog';

    public static function getNavigationLabel(): string
    {
        return trans_choice('reverse-proxy::strings.target', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('reverse-proxy::strings.target', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('reverse-proxy::strings.target', 2);
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('reverse-proxy::strings.navigation_group');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(trans('reverse-proxy::strings.name')),
                TextColumn::make('base_url')
                    ->label(trans('reverse-proxy::strings.base_url')),
                TextColumn::make('variant')
                    ->label(trans('reverse-proxy::strings.variant'))
                    ->badge()
                    ->placeholder(trans('reverse-proxy::strings.not_detected'))
                    ->formatStateUsing(fn (ProxyTarget $target) => $target->variantEnum()?->getLabel()),
                TextColumn::make('domains_count')
                    ->label(trans_choice('reverse-proxy::strings.domain', 2))
                    ->counts('domains'),
                IconColumn::make('is_default')
                    ->label(trans('reverse-proxy::strings.is_default'))
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('test')
                    ->label(trans('reverse-proxy::strings.test_connection'))
                    ->tooltip(trans('reverse-proxy::strings.test_connection'))
                    ->icon('tabler-plug-connected')
                    ->action(function (ProxyTarget $target) {
                        try {
                            $status = $target->resolveDriver()->testConnection();

                            Notification::make()
                                ->title(trans('reverse-proxy::strings.notifications.connected'))
                                ->body($status->summary())
                                ->success()
                                ->persistent()
                                ->send();
                        } catch (Exception $exception) {
                            Notification::make()
                                ->title(trans('reverse-proxy::strings.notifications.not_connected'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                // A page rather than a modal, so the stream port pool is reachable.
                EditAction::make()
                    ->url(fn (ProxyTarget $target) => EditProxyTarget::getUrl(['record' => $target])),
                DeleteAction::make()
                    ->modalDescription(trans('reverse-proxy::strings.delete_target_warning')),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false),
            ])
            ->emptyStateIcon('tabler-server-cog')
            ->emptyStateDescription(trans('reverse-proxy::strings.no_targets_description'))
            ->emptyStateHeading(trans('reverse-proxy::strings.no_targets'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(trans('reverse-proxy::strings.name'))
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('base_url')
                    ->label(trans('reverse-proxy::strings.base_url'))
                    ->required()
                    ->url()
                    ->placeholder('https://npm.example.com:81')
                    ->helperText(trans('reverse-proxy::strings.base_url_help'))
                    ->columnSpanFull(),
                TextInput::make('identity')
                    ->label(trans('reverse-proxy::strings.identity'))
                    ->required()
                    ->email()
                    ->helperText(trans('reverse-proxy::strings.identity_help')),
                TextInput::make('secret')
                    ->label(trans('reverse-proxy::strings.secret'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    // Leaving this blank on edit keeps the stored password.
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(trans('reverse-proxy::strings.secret_help')),
                Toggle::make('verify_tls')
                    ->label(trans('reverse-proxy::strings.verify_tls'))
                    ->default(true)
                    ->helperText(trans('reverse-proxy::strings.verify_tls_help')),
                Toggle::make('is_default')
                    ->label(trans('reverse-proxy::strings.is_default'))
                    ->helperText(trans('reverse-proxy::strings.is_default_help')),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            StreamPortRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProxyTargets::route('/'),
            'edit' => EditProxyTarget::route('/{record}/edit'),
        ];
    }
}
