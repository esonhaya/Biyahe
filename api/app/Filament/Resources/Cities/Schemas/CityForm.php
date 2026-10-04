<?php

namespace App\Filament\Resources\Cities\Schemas;

use App\Filament\Support\VerificationStateField;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('country_code')
                    ->required()
                    ->default('PH'),
                Toggle::make('is_active')
                    ->required(),
                VerificationStateField::make(),
                Toggle::make('is_demo')
                    ->live()
                    ->required(),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
