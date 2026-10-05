<?php

namespace App\Filament\Resources\TransferRelationships;

use App\Filament\Resources\TransferRelationships\Pages\CreateTransferRelationship;
use App\Filament\Resources\TransferRelationships\Pages\EditTransferRelationship;
use App\Filament\Resources\TransferRelationships\Pages\ListTransferRelationships;
use App\Filament\Resources\TransferRelationships\Schemas\TransferRelationshipForm;
use App\Filament\Resources\TransferRelationships\Tables\TransferRelationshipsTable;
use App\Models\TransferRelationship;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TransferRelationshipResource extends Resource
{
    protected static ?string $model = TransferRelationship::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'stable_id';

    public static function form(Schema $schema): Schema
    {
        return TransferRelationshipForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransferRelationshipsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransferRelationships::route('/'),
            'create' => CreateTransferRelationship::route('/create'),
            'edit' => EditTransferRelationship::route('/{record}/edit'),
        ];
    }
}
