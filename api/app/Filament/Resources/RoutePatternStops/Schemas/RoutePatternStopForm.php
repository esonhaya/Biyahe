<?php

namespace App\Filament\Resources\RoutePatternStops\Schemas;

use App\Filament\Support\VerificationStateField;
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
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('sequence')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->integer(),
                VerificationStateField::make('service_verification_state', 'Service association evidence'),
                Select::make('service_provenance_source_id')
                    ->relationship('serviceProvenanceSource', 'title'),
                VerificationStateField::make('sequence_verification_state', 'Sequence evidence'),
                Select::make('sequence_provenance_source_id')
                    ->relationship('sequenceProvenanceSource', 'title'),
                Toggle::make('boarding_allowed')
                    ->required(),
                VerificationStateField::make('boarding_verification_state', 'Boarding permission evidence'),
                Select::make('boarding_provenance_source_id')
                    ->relationship('boardingProvenanceSource', 'title'),
                Toggle::make('alighting_allowed')
                    ->required(),
                VerificationStateField::make('alighting_verification_state', 'Alighting permission evidence'),
                Select::make('alighting_provenance_source_id')
                    ->relationship('alightingProvenanceSource', 'title'),
                VerificationStateField::make(),
                Textarea::make('instruction')
                    ->columnSpanFull(),
                Textarea::make('service_metadata')
                    ->helperText('Optional JSON for informal service notes; do not enter formal schedules unless verified.')
                    ->columnSpanFull(),
            ]);
    }
}
