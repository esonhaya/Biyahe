<?php

namespace App\Filament\Resources\PlaceAliases;

use App\Filament\Resources\PlaceAliases\Pages\CreatePlaceAlias;
use App\Filament\Resources\PlaceAliases\Pages\EditPlaceAlias;
use App\Filament\Resources\PlaceAliases\Pages\ListPlaceAliases;
use App\Filament\Resources\PlaceAliases\Schemas\PlaceAliasForm;
use App\Filament\Resources\PlaceAliases\Tables\PlaceAliasesTable;
use App\Models\PlaceAlias;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlaceAliasResource extends Resource
{
    protected static ?string $model = PlaceAlias::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'alias';

    public static function form(Schema $schema): Schema
    {
        return PlaceAliasForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlaceAliasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaceAliases::route('/'),
            'create' => CreatePlaceAlias::route('/create'),
            'edit' => EditPlaceAlias::route('/{record}/edit'),
        ];
    }
}
