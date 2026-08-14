<?php

namespace Chromozone\ReverseProxy\Filament\Server\Resources\ProxyRoutes;

use App\Models\Allocation;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use App\Traits\Filament\HasLimitBadge;
use Chromozone\ReverseProxy\Exceptions\ProxyDriverException;
use Chromozone\ReverseProxy\Filament\Server\Resources\ProxyRoutes\Pages\ListProxyRoutes;
use Chromozone\ReverseProxy\Models\ProxyDomain;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Rules\DnsLabel;
use Chromozone\ReverseProxy\Rules\NotOnBlacklist;
use Chromozone\ReverseProxy\Services\ConnectivityCheck;
use Chromozone\ReverseProxy\Services\DnsPreflight;
use Chromozone\ReverseProxy\Services\ForwardHostResolver;
use Chromozone\ReverseProxy\Services\ProxyRouteService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\IconSize;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class ProxyRouteResource extends Resource
{
    // Aliased because this class defines its own canAccess(), and in PHP a class
    // method takes precedence over a trait's - so the trait's version would never
    // run, and Server::isInConflictState() would never be consulted.
    use BlockAccessInConflict {
        canAccess as blockedInConflictState;
    }
    use HasLimitBadge;

    protected static ?string $model = ProxyRoute::class;

    protected static ?string $slug = 'proxies';

    protected static ?int $navigationSort = 8;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-route';

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        // blockedInConflictState() denies while the server is suspended, mid
        // transfer, not yet installed, restoring a backup, or its node is under
        // maintenance - then chains to parent::canAccess() itself.
        return static::blockedInConflictState()
            && user()?->can('proxy-route.read', $server)
            // A limit of 0 means an admin has not granted this server the feature.
            && static::getBadgeLimit() > 0
            && $server->allocations()->exists()
            && ProxyDomain::query()->where('allow_user_routes', true)->exists();
    }

    public static function getNavigationLabel(): string
    {
        return trans_choice('reverse-proxy::strings.route', 2);
    }

    public static function getModelLabel(): string
    {
        return trans_choice('reverse-proxy::strings.route', 1);
    }

    public static function getPluralModelLabel(): string
    {
        return trans_choice('reverse-proxy::strings.route', 2);
    }

    protected static function getBadgeCount(): int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return ProxyRoute::query()->where('server_id', $server->id)->count();
    }

    protected static function getBadgeLimit(): int
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return (int) ($server->proxy_route_limit ?? 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hostname')
                    ->label(trans('reverse-proxy::strings.hostname'))
                    ->state(fn (ProxyRoute $route) => $route->hostname)
                    ->url(fn (ProxyRoute $route) => $route->url, shouldOpenInNewTab: true)
                    ->icon('tabler-external-link')
                    ->iconPosition(IconPosition::After),
                // Shown because a proxy can be perfectly valid and still point
                // somewhere unreachable - that is invisible without this.
                TextColumn::make('destination')
                    ->label(trans('reverse-proxy::strings.destination'))
                    ->state(fn (ProxyRoute $route) => static::describeTarget($route))
                    ->tooltip(trans('reverse-proxy::strings.destination_help')),
                TextColumn::make('last_synced_at')
                    ->label(trans('reverse-proxy::strings.last_synced'))
                    ->since()
                    ->placeholder(trans('reverse-proxy::strings.never_synced'))
                    // Deliberately not the raw last_error: it can quote the proxy
                    // manager's address and error text. Admins see the detail on
                    // the server's admin page.
                    ->description(fn (ProxyRoute $route) => filled($route->last_error)
                        ? trans('reverse-proxy::strings.errors.sync_failed')
                        : null),
            ])
            ->recordActions([
                Action::make('check')
                    ->label(trans('reverse-proxy::strings.check'))
                    ->icon('tabler-plug-connected')
                    ->action(fn (ProxyRoute $record) => static::runCheck($record)),
                EditAction::make()
                    ->authorize(fn () => user()?->can('proxy-route.update', Filament::getTenant()))
                    ->action(fn (array $data, ProxyRoute $record) => static::persist($data, $record)),
                DeleteAction::make()
                    ->authorize(fn () => user()?->can('proxy-route.delete', Filament::getTenant())),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->icon('tabler-route')
                    ->authorize(fn () => user()?->can('proxy-route.create', Filament::getTenant()))
                    ->tooltip(fn () => static::atLimit()
                        ? trans('reverse-proxy::strings.limit_reached')
                        : trans('reverse-proxy::strings.create_route'))
                    ->disabled(fn () => static::atLimit())
                    ->color(fn () => static::atLimit() ? 'danger' : 'primary')
                    ->createAnother(false)
                    ->hiddenLabel()
                    ->iconButton()
                    ->iconSize(IconSize::ExtraLarge)
                    ->action(fn (array $data) => static::persist($data)),
            ])
            ->emptyStateIcon('tabler-route')
            ->emptyStateDescription(trans('reverse-proxy::strings.no_routes_description'))
            ->emptyStateHeading(trans('reverse-proxy::strings.no_routes'));
    }

    public static function form(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $schema
            ->columns(2)
            ->components([
                TextInput::make('label')
                    ->label(trans('reverse-proxy::strings.hostname_label'))
                    ->required()
                    ->maxLength(63)
                    ->rule(new DnsLabel())
                    ->rule(new NotOnBlacklist())
                    // Normalised as it is typed so the uniqueness rule and the
                    // blacklist both see the value that will actually be stored.
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('label', Str::lower((string) $state)))
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('domain_id', $get('domain_id')),
                    )
                    ->suffix(fn (Get $get) => '.' . ProxyDomain::query()->find($get('domain_id'))?->name)
                    ->columnSpanFull(),
                Select::make('domain_id')
                    ->label(trans_choice('reverse-proxy::strings.domain', 1))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->options(fn () => static::userDomains()->pluck('name', 'id')->all())
                    ->default(fn () => static::userDomains()->value('id'))
                    ->hidden(fn () => static::userDomains()->count() <= 1)
                    ->dehydratedWhenHidden()
                    ->live()
                    ->columnSpanFull(),
                Select::make('allocation_id')
                    ->label(trans('reverse-proxy::strings.port'))
                    ->required()
                    ->selectablePlaceholder(false)
                    // Allocation notes are where users label their ports, so show them.
                    ->options(fn () => $server->allocations
                        ->mapWithKeys(fn (Allocation $allocation) => [
                            $allocation->id => $allocation->port . (filled($allocation->notes) ? ' - ' . $allocation->notes : ''),
                        ])
                        ->all())
                    // Preselect the port the user arrived from, when they came via
                    // the "Create proxy" action on the Network page.
                    ->default(fn () => static::allocationFromRequest($server) ?? $server->allocation_id)
                    ->helperText(trans('reverse-proxy::strings.port_help'))
                    ->live(),
                Select::make('forward_scheme')
                    ->label(trans('reverse-proxy::strings.forward_scheme'))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->options([
                        'http' => 'HTTP',
                        'https' => 'HTTPS',
                    ])
                    ->default('http')
                    ->helperText(trans('reverse-proxy::strings.forward_scheme_help'))
                    ->live(),
                // Shows the address the proxy will actually dial, before saving.
                // Without it the destination is only visible inside the proxy
                // manager, after the fact.
                Placeholder::make('destination')
                    ->label(trans('reverse-proxy::strings.destination'))
                    ->content(fn (Get $get) => static::previewTarget($server, $get))
                    ->helperText(trans('reverse-proxy::strings.destination_help'))
                    ->columnSpanFull(),
                Toggle::make('websockets')
                    ->label(trans('reverse-proxy::strings.websockets'))
                    ->default(true)
                    ->helperText(trans('reverse-proxy::strings.websockets_help')),
                Toggle::make('block_exploits')
                    ->label(trans('reverse-proxy::strings.block_exploits'))
                    ->default(true),
            ]);
    }

    public static function atLimit(): bool
    {
        return static::getBadgeCount() >= static::getBadgeLimit();
    }

    /** Live preview for the form, resolved from the selected port. */
    public static function previewTarget(Server $server, Get $get): string
    {
        $allocation = $server->allocations->firstWhere('id', (int) $get('allocation_id'));

        if (is_null($allocation)) {
            return trans('reverse-proxy::strings.destination_unavailable');
        }

        try {
            return sprintf(
                '%s://%s:%d',
                $get('forward_scheme') ?: 'http',
                app(ForwardHostResolver::class)->resolveForAllocation($allocation),
                $allocation->port,
            );
        } catch (Exception $exception) {
            return $exception->getMessage();
        }
    }

    /** Never throws: an unresolvable destination is information, not a crash. */
    public static function describeTarget(ProxyRoute $route): string
    {
        try {
            return $route->forwardTarget();
        } catch (Exception) {
            return trans('reverse-proxy::strings.destination_unavailable');
        }
    }

    public static function runCheck(ProxyRoute $route): void
    {
        $result = app(ConnectivityCheck::class)->check($route);

        $body = $result['target'] . ' - ' . $result['detail']
            . "\n" . trans('reverse-proxy::strings.notifications_check.' . ($result['dns'] ? 'dns_ok' : 'dns_missing'))
            . "\n" . trans('reverse-proxy::strings.notifications_check.from_panel');

        $notification = Notification::make()
            ->title(trans('reverse-proxy::strings.notifications_check.' . ($result['reachable'] ? 'reachable' : 'unreachable')))
            ->body($body)
            ->persistent();

        $result['reachable'] && $result['dns']
            ? $notification->success()->send()
            : $notification->warning()->send();
    }

    /**
     * Allocation passed as ?allocation=<id> from the Network page action. Only
     * honoured when it really belongs to this server.
     */
    protected static function allocationFromRequest(Server $server): ?int
    {
        $allocationId = (int) request()->query('allocation');

        if ($allocationId <= 0) {
            return null;
        }

        return $server->allocations()->whereKey($allocationId)->exists() ? $allocationId : null;
    }

    /** @return Builder<ProxyDomain> */
    protected static function userDomains(): Builder
    {
        return ProxyDomain::query()->where('allow_user_routes', true);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    protected static function persist(array $data, ?ProxyRoute $route = null): ProxyRoute
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $data['server_id'] = $server->id;

        try {
            $route = app(ProxyRouteService::class)->handle($data, $route);
        } catch (Exception $exception) {
            // Driver failures carry the proxy manager's address and its own error
            // text, which a server owner has no business seeing. Guard failures
            // (quota, blacklist, wrong domain) are written for them, so those pass
            // through unchanged.
            $isInternal = $exception instanceof ProxyDriverException;

            if ($isInternal) {
                report($exception);
            }

            Notification::make()
                ->title(trans('reverse-proxy::strings.notifications.not_synced'))
                ->body($isInternal ? trans('reverse-proxy::strings.errors.sync_failed') : $exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt();
        }

        Notification::make()
            ->title(trans('reverse-proxy::strings.notifications.synced'))
            ->body($route->url)
            ->success()
            ->send();

        // Non-blocking: DNS may simply not have propagated yet.
        if (!app(DnsPreflight::class)->resolves($route->hostname)) {
            Notification::make()
                ->title(trans('reverse-proxy::strings.notifications.dns_missing'))
                ->body(trans('reverse-proxy::strings.notifications.dns_missing_body', ['hostname' => $route->hostname]))
                ->warning()
                ->persistent()
                ->send();
        }

        return $route;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProxyRoutes::route('/'),
        ];
    }
}
