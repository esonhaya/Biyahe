<?php

namespace App\Filament\Resources\RoutePatterns\Schemas;

use App\Filament\Support\VerificationStateField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RoutePatternForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('route_id')
                    ->relationship('route', 'name')
                    ->required(),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('direction')
                    ->required(),
                Textarea::make('service_notes')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                VerificationStateField::make(),
                Toggle::make('is_demo')
                    ->live()
                    ->required(),
                Textarea::make('geometry')
                    ->label('Route geometry WKT (WGS 84)')
                    ->helperText('Use SRID=4326;MULTILINESTRING((longitude latitude,...)).')
                    ->columnSpanFull(),
            ]);
    }
}
