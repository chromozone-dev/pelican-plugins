<?php

namespace Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyDomains;

use Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyDomains\Pages\ManageProxyDomains;
use Chromozone\ReverseProxy\Models\ProxyDomain;
use Chromozone\ReverseProxy\Models\ProxyTarget;
use Exception;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProxyDomainResource extends Resource
{
    protected static ?string $model = ProxyDomain::class;

    protected static ?string $slug = 'proxy-domains';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-world-www';

    /**
     * Certificates are fetched over the network, and the picker re-evaluates its
     * options on every Livewire round trip. Memoise per request per target.
     *
     * @var array<int, array<int, array{id: int, nice_name: string, domain_names: string[], expires_on: string|null}>>
     */
    protected static array $certificateCache = [];

    public static function getNavigationLabel(): string
    {
        return trans_choice('reverse-proxy::strings.domain', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('reverse-proxy::strings.domain', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('reverse-proxy::strings.domain', 2);
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
                    ->label(trans('reverse-proxy::strings.name'))
                    ->description(fn (ProxyDomain $domain) => '*.' . $domain->name),
                TextColumn::make('target.name')
                    ->label(trans_choice('reverse-proxy::strings.target', 1)),
                TextColumn::make('certificate_id')
                    ->label(trans('reverse-proxy::strings.certificate'))
                    ->placeholder(trans('reverse-proxy::strings.no_certificate'))
                    ->formatStateUsing(fn (?int $state) => blank($state) ? null : '#' . $state),
                TextColumn::make('routes_count')
                    ->label(trans_choice('reverse-proxy::strings.route', 2))
                    ->counts('routes'),
                IconColumn::make('allow_user_routes')
                    ->label(trans('reverse-proxy::strings.allow_user_routes'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(trans('reverse-proxy::strings.delete_domain_warning')),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false)
                    ->hidden(fn () => ProxyTarget::query()->doesntExist()),
            ])
            ->emptyStateIcon('tabler-world-www')
            ->emptyStateDescription(trans('reverse-proxy::strings.no_domains_description'))
            ->emptyStateHeading(trans('reverse-proxy::strings.no_domains'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('target_id')
                    ->label(trans_choice('reverse-proxy::strings.target', 1))
                    ->relationship('target', 'name')
                    ->required()
                    ->selectablePlaceholder(false)
                    ->default(fn () => ProxyTarget::defaultTarget()?->id)
                    ->hidden(fn () => ProxyTarget::query()->count() <= 1)
                    ->dehydratedWhenHidden()
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('name')
                    ->label(trans('reverse-proxy::strings.name'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->placeholder('example.com')
                    ->helperText(trans('reverse-proxy::strings.domain_name_help'))
                    // A domain flows straight into domain_names on every proxy
                    // entry created under it, so it has to be a real hostname.
                    ->rule('regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/')
                    ->validationMessages(['regex' => trans('reverse-proxy::strings.validation.invalid_domain')])
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                        // Normalised here so validation and the unique rule see the
                        // value the model will actually store.
                        $state = Str::lower(trim((string) $state));
                        $set('name', $state);

                        // Preselect the wildcard certificate that already covers
                        // this domain, which is what an admin would pick by hand.
                        if ($state === '' || filled($get('certificate_id'))) {
                            return;
                        }

                        $suggestion = static::suggestCertificate($get('target_id'), $state);

                        if (!is_null($suggestion)) {
                            $set('certificate_id', $suggestion);
                        }
                    })
                    ->columnSpanFull(),
                Select::make('certificate_id')
                    ->label(trans('reverse-proxy::strings.certificate'))
                    ->helperText(trans('reverse-proxy::strings.certificate_help'))
                    ->options(fn (Get $get) => static::certificateOptions($get('target_id')))
                    ->searchable()
                    ->columnSpanFull(),
                Toggle::make('force_ssl')
                    ->label(trans('reverse-proxy::strings.force_ssl'))
                    ->default(true)
                    ->helperText(trans('reverse-proxy::strings.force_ssl_help')),
                Toggle::make('allow_user_routes')
                    ->label(trans('reverse-proxy::strings.allow_user_routes'))
                    ->default(true)
                    ->helperText(trans('reverse-proxy::strings.allow_user_routes_help')),
            ]);
    }

    /** @return array<int, string> */
    protected static function certificateOptions(mixed $targetId): array
    {
        return collect(static::certificates($targetId))
            ->mapWithKeys(fn (array $certificate) => [
                $certificate['id'] => $certificate['nice_name'] . ' (' . implode(', ', $certificate['domain_names']) . ')',
            ])
            ->all();
    }

    protected static function suggestCertificate(mixed $targetId, string $domain): ?int
    {
        foreach (static::certificates($targetId) as $certificate) {
            foreach ($certificate['domain_names'] as $certificateDomain) {
                if (strcasecmp($certificateDomain, '*.' . $domain) === 0) {
                    return $certificate['id'];
                }
            }
        }

        return null;
    }

    /** @return array<int, array{id: int, nice_name: string, domain_names: string[], expires_on: string|null}> */
    protected static function certificates(mixed $targetId): array
    {
        $target = ProxyTarget::query()->find($targetId) ?? ProxyTarget::defaultTarget();

        if (is_null($target)) {
            return [];
        }

        if (array_key_exists($target->id, static::$certificateCache)) {
            return static::$certificateCache[$target->id];
        }

        try {
            return static::$certificateCache[$target->id] = $target->resolveDriver()->listCertificates();
        } catch (Exception $exception) {
            // An unreachable proxy manager leaves the picker empty rather than
            // breaking the form - but say so, because an empty list otherwise
            // looks like "no certificates exist". The driver caches auth failures
            // briefly, so this cannot spam on every Livewire round trip.
            Notification::make()
                ->title(trans('reverse-proxy::strings.certificates_unavailable'))
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return static::$certificateCache[$target->id] = [];
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProxyDomains::route('/'),
        ];
    }
}
