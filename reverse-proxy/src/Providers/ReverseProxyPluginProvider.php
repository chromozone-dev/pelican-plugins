<?php

namespace Chromozone\ReverseProxy\Providers;

use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Filament\Server\Resources\Allocations\AllocationResource;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use Chromozone\ReverseProxy\Console\Commands\ReconcileCommand;
use Chromozone\ReverseProxy\Filament\Admin\Resources\Servers\RelationManagers\ProxyRouteRelationManager;
use Chromozone\ReverseProxy\Filament\Server\Resources\ProxyRoutes\ProxyRouteResource;
use Chromozone\ReverseProxy\Models\ProxyRoute;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class ReverseProxyPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        ServerResource::registerCustomRelations(ProxyRouteRelationManager::class);

        Role::registerCustomDefaultPermissions('proxy_target');
        Role::registerCustomDefaultPermissions('proxy_domain');
        // Gates the admin-side relation manager, which would otherwise be
        // authorized by nothing at all.
        Role::registerCustomDefaultPermissions('proxy_route');
        Role::registerCustomModelIcon('proxy_target', 'tabler-server-cog');
        Role::registerCustomModelIcon('proxy_domain', 'tabler-world-www');
        Role::registerCustomModelIcon('proxy_route', 'tabler-route');

        $this->commands([
            ReconcileCommand::class,
        ]);
    }

    public function boot(): void
    {
        Server::resolveRelationUsing(
            'proxyRoutes',
            fn (Server $server) => $server->hasMany(ProxyRoute::class, 'server_id', 'id'),
        );

        // The database cascade removes the local rows, but nothing tells the proxy
        // manager. Delete through the model so each remote entry goes too, and
        // never let an unreachable proxy manager block deleting a server.
        Server::deleting(function (Server $server) {
            /** @phpstan-ignore property.notFound */
            foreach ($server->proxyRoutes as $route) {
                try {
                    $route->delete();
                } catch (Exception $exception) {
                    report($exception);
                }
            }
        });

        // Suspending a server has to take its hostnames off the air. Core writes
        // the status with $server->update(), so this is observable - unlike the
        // allocation changes elsewhere in this file.
        Server::updated(function (Server $server) {
            if (!$server->wasChanged('status')) {
                return;
            }

            /** @phpstan-ignore property.notFound */
            foreach ($server->proxyRoutes as $route) {
                try {
                    $route->syncToProxy();
                } catch (Exception $exception) {
                    report($exception);
                }
            }
        });

        // Deleting a node cascades its allocations in the database, which cascades
        // our routes - again with no model events, so their entries would stay
        // live in the proxy manager. Core's own Node::deleting only blocks while
        // servers remain, which does not cover a node emptied by transfers.
        Node::deleting(function (Node $node) {
            ProxyRoute::query()
                ->whereHas('allocation', fn ($query) => $query->where('node_id', $node->id))
                ->get()
                ->each(function (ProxyRoute $route) {
                    try {
                        $route->delete();
                    } catch (Exception $exception) {
                        report($exception);
                    }
                });
        });

        Subuser::registerCustomPermissions(
            'proxy-route',
            ['read', 'create', 'update', 'delete'],
            'reverse-proxy::strings.permissions',
            'tabler-route',
        );

        $this->registerActivityLabels();
        $this->registerReconcileSchedule();
        $this->registerNetworkPageAction();
        $this->registerNodeForwardHostField();
    }

    /**
     * Surfaces nodes.proxy_forward_host, which ForwardHostResolver reads and its
     * error message tells admins to set, on the node edit screen - it previously
     * had no UI anywhere, so that instruction was impossible to follow.
     */
    private function registerNodeForwardHostField(): void
    {
        // Node declares an explicit $fillable allow-list that cannot know about a
        // column added by a plugin, so the value would be silently dropped on
        // save. $fillable is per-instance state, and Filament saves the instance
        // it retrieved, so merging on retrieval is enough.
        Node::retrieved(fn (Node $node) => $node->mergeFillable(['proxy_forward_host']));

        EditNode::registerCustomTabs(
            TabPosition::After,
            Tab::make('reverse_proxy')
                ->label(trans('reverse-proxy::strings.navigation_group'))
                ->icon('tabler-route')
                ->schema([
                    TextInput::make('proxy_forward_host')
                        ->label(trans('reverse-proxy::strings.forward_host'))
                        ->helperText(trans('reverse-proxy::strings.forward_host_help'))
                        ->placeholder(fn (?Node $record) => $record?->fqdn)
                        ->columnSpanFull(),
                ]),
        );
    }

    /**
     * ActivityLog::getLabel() resolves an event name against the app's
     * `activity` translation group. Pelican registers a plugin's lang directory
     * under the plugin's own namespace instead, so our keys are bridged across
     * here - otherwise the activity page renders the raw key.
     */
    private function registerActivityLabels(): void
    {
        $locale = app()->getLocale();

        // Touch the core group first. addLines() marks a group as loaded, which
        // would otherwise stop lang/<locale>/activity.php from ever loading and
        // blank out every other activity label in the panel.
        Lang::get('activity', [], $locale);

        $labels = trans('reverse-proxy::strings.activity', [], $locale);

        if (!is_array($labels)) {
            return;
        }

        $lines = [];

        foreach ($labels as $event => $text) {
            $lines["activity.server.proxy-route.$event"] = $text;
        }

        Lang::addLines($lines, $locale);
    }

    /**
     * Drift in the proxy manager is expected, so repair runs on a cron rather
     * than waiting for someone to remember the command. Pruning is never
     * scheduled - it deletes remote entries and stays a manual decision.
     */
    private function registerReconcileSchedule(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $cron = trim((string) config('reverse-proxy.reconcile_cron'));

        if ($cron === '') {
            return;
        }

        $command = 'p:reverse-proxy:reconcile';

        if (config('reverse-proxy.reconcile_repair', true)) {
            $command .= ' --repair';
        }

        Schedule::command($command)
            ->cron($cron)
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Add a "Create proxy" action to each row of the server Network page, where
     * users already go to look at their ports.
     */
    private function registerNetworkPageAction(): void
    {
        AllocationResource::modifyTable(function (Table $table) {
            // pushRecordActions, NOT recordActions: the latter resets the array
            // before adding, which would silently delete Pelican's own actions.
            return $table->pushRecordActions([
                Action::make('createProxy')
                    ->label(trans('reverse-proxy::strings.create_route'))
                    ->tooltip(trans('reverse-proxy::strings.create_route'))
                    ->icon('tabler-route')
                    ->hiddenLabel()
                    ->visible(fn () => ProxyRouteResource::canAccess() && !ProxyRouteResource::atLimit())
                    ->url(fn (Allocation $allocation) => ProxyRouteResource::getUrl('index', [
                        'allocation' => $allocation->id,
                    ])),
            ]);
        });
    }
}
