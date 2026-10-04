<?php

namespace App\Filament\Resources\Fares\Schemas;

use App\Enums\VerificationState;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FareForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('route_id')
                    ->relationship('route', 'name')
                    ->required(),
                Select::make('vehicle_type_id')
                    ->relationship('vehicleType', 'name')
                    ->required(),
                Select::make('route_pattern_id')
                    ->relationship('routePattern', 'name'),
                Select::make('origin_stop_id')
                    ->relationship('originStop', 'name'),
                Select::make('destination_stop_id')
                    ->relationship('destinationStop', 'name'),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                TextInput::make('fare_class')
                    ->required()
                    ->default('REGULAR'),
                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                TextInput::make('currency')
                    ->required()
                    ->default('PHP'),
                TextInput::make('min_distance_km')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('max_distance_km')
                    ->numeric()
                    ->minValue(0),
                DatePicker::make('effective_from')
                    ->required(),
                DatePicker::make('effective_until'),
                Select::make('verification_state')
                    ->options(VerificationState::class)
                    ->default('DRAFT')
                    ->required(),
                Toggle::make('is_demo')
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
