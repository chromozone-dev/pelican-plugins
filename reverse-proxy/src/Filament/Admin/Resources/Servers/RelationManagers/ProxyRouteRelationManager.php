<?php

namespace Chromozone\ReverseProxy\Filament\Admin\Resources\Servers\RelationManagers;

use App\Models\Allocation;
use App\Models\Server;
use Chromozone\ReverseProxy\Enums\RouteType;
use Chromozone\ReverseProxy\Filament\Server\Resources\ProxyRoutes\ProxyRouteResource;
use Chromozone\ReverseProxy\Models\ProxyDomain;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Chromozone\ReverseProxy\Models\ProxyStreamPort;
use Chromozone\ReverseProxy\Rules\DnsLabel;
use Chromozone\ReverseProxy\Services\ProxyRouteService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * @method Server getOwnerRecord()
 */
class ProxyRouteRelationManager extends RelationManager
{
    protected static string $relationship = 'proxyRoutes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans_choice('reverse-proxy::strings.route', 2);
    }

    /**
     * Without this, Filament falls back to a policy lookup for ProxyRoute; there
     * is no ProxyRoutePolicy (routes are server-scoped and gated per subuser on
     * the server panel), so authorization resolved to "allow" and any admin who
     * could open a server's edit page could publish hostnames on domains marked
     * allow_user_routes = false and rewrite the per-server limit.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return user()?->can('viewList proxy_route') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(function () {
                $limit = $this->getOwnerRecord()->proxy_route_limit ?? 0;

                return trans_choice('reverse-proxy::strings.route', 2)
                    . ' (' . trans('reverse-proxy::strings.limit') . ': ' . $limit . ')';
            })
            ->columns([
                TextColumn::make('hostname')
                    ->label(trans('reverse-proxy::strings.hostname'))
                    // For a stream this includes the proxy's incoming port, which
                    // is what a player actually types.
                    ->state(fn (ProxyRoute $route) => $route->publicAddress())
                    ->description(fn (ProxyRoute $route) => $route->type->getLabel()),
                TextColumn::make('destination')
                    ->label(trans('reverse-proxy::strings.destination'))
                    ->state(fn (ProxyRoute $route) => ProxyRouteResource::describeTarget($route))
                    ->tooltip(trans('reverse-proxy::strings.destination_help')),
                TextColumn::make('domain.target.name')
                    ->label(trans_choice('reverse-proxy::strings.target', 1)),
                TextColumn::make('last_synced_at')
                    ->label(trans('reverse-proxy::strings.last_synced'))
                    ->since()
                    ->placeholder(trans('reverse-proxy::strings.never_synced'))
                    ->description(fn (ProxyRoute $route) => $route->last_error),
            ])
            ->recordActions([
                Action::make('check')
                    ->label(trans('reverse-proxy::strings.check'))
                    ->icon('tabler-plug-connected')
                    ->action(fn (ProxyRoute $record) => ProxyRouteResource::runCheck($record)),
                EditAction::make()
                    ->authorize(fn () => user()?->can('update proxy_route'))
                    ->action(fn (array $data, ProxyRoute $record) => $this->persist($data, $record)),
                DeleteAction::make()
                    ->authorize(fn () => user()?->can('delete proxy_route')),
            ])
            ->headerActions([
                Action::make('change_limit')
                    ->tooltip(trans('reverse-proxy::strings.change_limit'))
                    ->icon('tabler-filter-2-edit')
                    // Changing the quota is a write to the server, not the route.
                    ->authorize(fn () => user()?->can('update', $this->getOwnerRecord()))
                    ->schema([
                        TextInput::make('limit')
                            ->label(trans('reverse-proxy::strings.limit'))
                            ->numeric()
                            ->required()
                            ->default($this->getOwnerRecord()->proxy_route_limit ?? 0)
                            ->minValue(0)
                            ->helperText(trans('reverse-proxy::strings.limit_help')),
                    ])
                    ->action(function (array $data) {
                        $oldLimit = $this->getOwnerRecord()->proxy_route_limit ?? 0;
                        $newLimit = (int) $data['limit'];

                        $this->getOwnerRecord()->update(['proxy_route_limit' => $newLimit]);

                        Notification::make()
                            ->title(trans('reverse-proxy::strings.limit_changed'))
                            ->body($oldLimit . ' -> ' . $newLimit)
                            ->success()
                            ->send();
                    }),
                CreateAction::make()
                    ->authorize(fn () => user()?->can('create proxy_route'))
                    ->visible(fn () => ProxyDomain::query()->exists())
                    ->disabled(fn () => $this->getOwnerRecord()->allocations()->doesntExist())
                    ->createAnother(false)
                    ->action(fn (array $data) => $this->persist($data)),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('label')
                    ->label(trans('reverse-proxy::strings.hostname_label'))
                    ->required()
                    ->maxLength(63)
                    ->rule(new DnsLabel())
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('label', Str::lower((string) $state)))
                    ->unique(
                        table: 'proxy_routes',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('domain_id', $get('domain_id')),
                    )
                    ->suffix(fn (Get $get) => '.' . ProxyDomain::query()->find($get('domain_id'))?->name)
                    ->columnSpanFull(),
                // Admins may use any domain, including ones withheld from users.
                Select::make('domain_id')
                    ->label(trans_choice('reverse-proxy::strings.domain', 1))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->relationship('domain', 'name')
                    ->default(fn () => ProxyDomain::query()->value('id'))
                    ->preload()
                    ->searchable()
                    ->live()
                    ->columnSpanFull(),
                Select::make('allocation_id')
                    ->label(trans('reverse-proxy::strings.port'))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->options(fn () => $this->getOwnerRecord()->allocations
                        ->mapWithKeys(fn (Allocation $allocation) => [
                            $allocation->id => $allocation->port . (filled($allocation->notes) ? ' - ' . $allocation->notes : ''),
                        ])
                        ->all())
                    ->default(fn () => $this->getOwnerRecord()->allocation_id),
                Select::make('type')
                    ->label(trans('reverse-proxy::strings.type'))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->options(RouteType::class)
                    ->default(RouteType::Http->value)
                    ->helperText(trans('reverse-proxy::strings.type_help'))
                    ->disabledOn('edit')
                    ->live()
                    ->columnSpanFull(),

                // HTTP only
                Select::make('forward_scheme')
                    ->label(trans('reverse-proxy::strings.forward_scheme'))
                    ->required()
                    ->selectablePlaceholder(false)
                    ->options([
                        'http' => 'HTTP',
                        'https' => 'HTTPS',
                    ])
                    ->default('http')
                    ->visible(fn (Get $get) => $get('type') !== RouteType::Stream->value),
                Toggle::make('websockets')
                    ->label(trans('reverse-proxy::strings.websockets'))
                    ->default(true)
                    ->visible(fn (Get $get) => $get('type') !== RouteType::Stream->value),
                Toggle::make('block_exploits')
                    ->label(trans('reverse-proxy::strings.block_exploits'))
                    ->default(true)
                    ->visible(fn (Get $get) => $get('type') !== RouteType::Stream->value),

                // Stream only
                Select::make('stream_port_id')
                    ->label(trans('reverse-proxy::strings.stream_port'))
                    ->required(fn (Get $get) => $get('type') === RouteType::Stream->value)
                    ->visible(fn (Get $get) => $get('type') === RouteType::Stream->value)
                    ->options(fn (?ProxyRoute $record) => static::availableStreamPorts($record))
                    ->helperText(trans('reverse-proxy::strings.stream_port_select_help'))
                    ->live()
                    ->columnSpanFull(),
                Toggle::make('stream_tcp')
                    ->label(trans('reverse-proxy::strings.forward_tcp'))
                    ->default(true)
                    ->visible(fn (Get $get) => $get('type') === RouteType::Stream->value),
                Toggle::make('stream_udp')
                    ->label(trans('reverse-proxy::strings.forward_udp'))
                    ->visible(fn (Get $get) => $get('type') === RouteType::Stream->value),
            ]);
    }

    /**
     * Unclaimed ports on the domain's proxy manager, plus whichever port this
     * route already holds. A port carries at most one route, so offering a taken
     * one would only produce a unique-constraint failure at save time.
     *
     * @return array<int, string>
     */
    protected static function availableStreamPorts(?ProxyRoute $record): array
    {
        return ProxyStreamPort::query()
            ->where(fn ($query) => $query->doesntHave('route')->when(
                $record?->stream_port_id,
                fn ($q, $id) => $q->orWhere('id', $id),
            ))
            ->orderBy('port')
            ->get()
            ->mapWithKeys(fn (ProxyStreamPort $port) => [$port->id => $port->getLabel()])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    protected function persist(array $data, ?ProxyRoute $route = null): ProxyRoute
    {
        $data['server_id'] = $this->getOwnerRecord()->id;

        try {
            // Admins are not subject to the per-server quota, and may use domains
            // that are withheld from users.
            return app(ProxyRouteService::class)->handle($data, $route, asUser: false);
        } catch (Exception $exception) {
            Notification::make()
                ->title(trans('reverse-proxy::strings.notifications.not_synced'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt();
        }
    }
}
