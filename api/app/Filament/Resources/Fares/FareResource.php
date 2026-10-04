<?php

namespace App\Filament\Resources\Fares;

use App\Filament\Resources\Fares\Pages\CreateFare;
use App\Filament\Resources\Fares\Pages\EditFare;
use App\Filament\Resources\Fares\Pages\ListFares;
use App\Filament\Resources\Fares\Schemas\FareForm;
use App\Filament\Resources\Fares\Tables\FaresTable;
use App\Models\Fare;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FareResource extends Resource
{
    protected static ?string $model = Fare::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'fare_class';

    public static function form(Schema $schema): Schema
    {
        return FareForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FaresTable::configure($table);
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
            'index' => ListFares::route('/'),
            'create' => CreateFare::route('/create'),
            'edit' => EditFare::route('/{record}/edit'),
        ];
    }
}
