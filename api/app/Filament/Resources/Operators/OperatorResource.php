<?php

namespace App\Filament\Resources\Operators;

use App\Filament\Resources\Operators\Pages\CreateOperator;
use App\Filament\Resources\Operators\Pages\EditOperator;
use App\Filament\Resources\Operators\Pages\ListOperators;
use App\Filament\Resources\Operators\Schemas\OperatorForm;
use App\Filament\Resources\Operators\Tables\OperatorsTable;
use App\Models\Operator;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OperatorResource extends Resource
{
    protected static ?string $model = Operator::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return OperatorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperatorsTable::configure($table);
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
            'index' => ListOperators::route('/'),
            'create' => CreateOperator::route('/create'),
            'edit' => EditOperator::route('/{record}/edit'),
        ];
    }
}
