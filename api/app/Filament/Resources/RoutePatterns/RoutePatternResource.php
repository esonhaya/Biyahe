<?php

namespace App\Filament\Resources\RoutePatterns;

use App\Filament\Resources\RoutePatterns\Pages\CreateRoutePattern;
use App\Filament\Resources\RoutePatterns\Pages\EditRoutePattern;
use App\Filament\Resources\RoutePatterns\Pages\ListRoutePatterns;
use App\Filament\Resources\RoutePatterns\Schemas\RoutePatternForm;
use App\Filament\Resources\RoutePatterns\Tables\RoutePatternsTable;
use App\Models\RoutePattern;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RoutePatternResource extends Resource
{
    protected static ?string $model = RoutePattern::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoutePatternForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoutePatternsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PatternStopsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoutePatterns::route('/'),
            'create' => CreateRoutePattern::route('/create'),
            'edit' => EditRoutePattern::route('/{record}/edit'),
        ];
    }
}
