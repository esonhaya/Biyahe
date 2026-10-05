<?php

namespace App\Filament\Resources\Stops\Schemas;

use App\Enums\BoardingPrecision;
use App\Filament\Support\VerificationStateField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->relationship('city', 'name')
                    ->required(),
                Select::make('nearby_place_id')
                    ->relationship('nearbyPlace', 'name'),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                Select::make('boarding_provenance_source_id')
                    ->relationship('boardingProvenanceSource', 'title')
                    ->label('Boarding evidence source'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(150),
                TextInput::make('code'),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('category')
                    ->required()
                    ->default('GENERAL'),
                Toggle::make('is_boarding_point')
                    ->required(),
                Textarea::make('boarding_notes')
                    ->columnSpanFull(),
                TextInput::make('boarding_side')
                    ->helperText('Optional side or direction label only when supported by evidence.'),
                Textarea::make('direction_note')
                    ->helperText('Optional direction/roadside note; do not infer it from geometry alone.')
                    ->columnSpanFull(),
                Select::make('boarding_precision')
                    ->options(BoardingPrecision::class)
                    ->default(BoardingPrecision::UNKNOWN->value)
                    ->required()
                    ->helperText('A stop coordinate does not prove an exact curbside boarding point.'),
                VerificationStateField::make('boarding_verification_state', 'Boarding evidence state'),
                Toggle::make('is_active')
                    ->required(),
                VerificationStateField::make(),
                Toggle::make('is_demo')
                    ->live()
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull(),
                Textarea::make('location')
                    ->required()
                    ->label('Location WKT (WGS 84)')
                    ->helperText('Use SRID=4326;POINT(longitude latitude).')
                    ->columnSpanFull(),
            ]);
    }
}
