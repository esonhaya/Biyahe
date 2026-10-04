<?php

namespace App\Filament\Resources\Routes\Schemas;

use App\Filament\Support\VerificationStateField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->relationship('city', 'name')
                    ->required(),
                Select::make('operator_id')
                    ->relationship('operator', 'name'),
                Select::make('vehicle_type_id')
                    ->relationship('vehicleType', 'name')
                    ->required(),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                TextInput::make('code')
                    ->required(),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('name')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                VerificationStateField::make(),
                Toggle::make('is_demo')
                    ->live()
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
