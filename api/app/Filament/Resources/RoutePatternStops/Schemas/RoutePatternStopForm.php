<?php

namespace App\Filament\Resources\RoutePatternStops\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RoutePatternStopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('route_pattern_id')
                    ->relationship('routePattern', 'name')
                    ->required(),
                Select::make('stop_id')
                    ->relationship('stop', 'name')
                    ->required(),
                TextInput::make('sequence')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->integer(),
                Toggle::make('boarding_allowed')
                    ->required(),
                Toggle::make('alighting_allowed')
                    ->required(),
                Textarea::make('instruction')
                    ->columnSpanFull(),
                Textarea::make('service_metadata')
                    ->helperText('Optional JSON for informal service notes; do not enter formal schedules unless verified.')
                    ->columnSpanFull(),
            ]);
    }
}
