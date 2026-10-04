<?php

namespace App\Filament\Resources\Places\Schemas;

use App\Filament\Support\VerificationStateField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlaceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('city_id')
                    ->relationship('city', 'name')
                    ->required(),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(150),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(150),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('category')
                    ->required()
                    ->maxLength(50),
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
                Textarea::make('location')
                    ->required()
                    ->label('Location WKT (WGS 84)')
                    ->helperText('Use SRID=4326;POINT(longitude latitude).')
                    ->columnSpanFull(),
            ]);
    }
}
