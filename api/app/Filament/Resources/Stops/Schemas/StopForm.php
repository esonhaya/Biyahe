<?php

namespace App\Filament\Resources\Stops\Schemas;

use App\Enums\VerificationState;
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
                TextInput::make('name')
                    ->required()
                    ->maxLength(150),
                TextInput::make('code'),
                TextInput::make('category')
                    ->required()
                    ->default('GENERAL'),
                Toggle::make('is_boarding_point')
                    ->required(),
                Textarea::make('boarding_notes')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                Select::make('verification_state')
                    ->options(VerificationState::class)
                    ->default('DRAFT')
                    ->required(),
                Toggle::make('is_demo')
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
