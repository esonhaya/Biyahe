<?php

namespace App\Filament\Resources\RoutePatternStops;

use App\Filament\Resources\RoutePatternStops\Pages\CreateRoutePatternStop;
use App\Filament\Resources\RoutePatternStops\Pages\EditRoutePatternStop;
use App\Filament\Resources\RoutePatternStops\Pages\ListRoutePatternStops;
use App\Filament\Resources\RoutePatternStops\Schemas\RoutePatternStopForm;
use App\Filament\Resources\RoutePatternStops\Tables\RoutePatternStopsTable;
use App\Models\RoutePatternStop;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RoutePatternStopResource extends Resource
{
    protected static ?string $model = RoutePatternStop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'sequence';

    public static function form(Schema $schema): Schema
    {
        return RoutePatternStopForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoutePatternStopsTable::configure($table);
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
            'index' => ListRoutePatternStops::route('/'),
            'create' => CreateRoutePatternStop::route('/create'),
            'edit' => EditRoutePatternStop::route('/{record}/edit'),
        ];
    }
}
