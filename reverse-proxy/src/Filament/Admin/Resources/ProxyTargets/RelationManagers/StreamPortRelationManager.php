<?php

namespace Chromozone\ReverseProxy\Filament\Admin\Resources\ProxyTargets\RelationManagers;

use Chromozone\ReverseProxy\Models\ProxyStreamPort;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StreamPortRelationManager extends RelationManager
{
    protected static string $relationship = 'streamPorts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return trans('reverse-proxy::strings.stream_ports');
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(trans('reverse-proxy::strings.stream_ports_help'))
            ->columns([
                TextColumn::make('port')
                    ->label(trans('reverse-proxy::strings.port')),
                IconColumn::make('tcp')
                    ->label('TCP')
                    ->boolean(),
                IconColumn::make('udp')
                    ->label('UDP')
                    ->boolean(),
                TextColumn::make('label')
                    ->label(trans('reverse-proxy::strings.name'))
                    ->placeholder('-'),
                // A port carries at most one route, so this doubles as "in use".
                TextColumn::make('route.label')
                    ->label(trans_choice('reverse-proxy::strings.route', 1))
                    ->state(fn (ProxyStreamPort $port) => $port->route?->hostname)
                    ->placeholder(trans('reverse-proxy::strings.unclaimed')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(trans('reverse-proxy::strings.delete_stream_port_warning')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->createAnother(false),
            ])
            ->emptyStateIcon('tabler-plug')
            ->emptyStateHeading(trans('reverse-proxy::strings.no_stream_ports'))
            ->emptyStateDescription(trans('reverse-proxy::strings.stream_ports_help'));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('port')
                    ->label(trans('reverse-proxy::strings.port'))
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(65535)
                    ->unique(table: 'proxy_stream_ports', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('target_id', $this->getOwnerRecord()->getKey()))
                    ->helperText(trans('reverse-proxy::strings.stream_port_help'))
                    ->columnSpanFull(),
                Toggle::make('tcp')
                    ->label('TCP')
                    ->default(true),
                Toggle::make('udp')
                    ->label('UDP'),
                TextInput::make('label')
                    ->label(trans('reverse-proxy::strings.name'))
                    ->placeholder('Minecraft')
                    ->columnSpanFull(),
            ]);
    }
}
