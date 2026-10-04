<?php

namespace App\Filament\Resources\PlaceAliases\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PlaceAliasForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('place_id')
                    ->relationship('place', 'name')
                    ->required(),
                TextInput::make('stable_id')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Assigned by the dataset importer; do not change manually.'),
                TextInput::make('alias')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('normalized_alias', Str::lower(trim($state ?? '')))),
                TextInput::make('normalized_alias')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
