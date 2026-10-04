<?php

namespace App\Filament\Resources\ProvenanceSources;

use App\Filament\Resources\ProvenanceSources\Pages\CreateProvenanceSource;
use App\Filament\Resources\ProvenanceSources\Pages\EditProvenanceSource;
use App\Filament\Resources\ProvenanceSources\Pages\ListProvenanceSources;
use App\Filament\Resources\ProvenanceSources\Schemas\ProvenanceSourceForm;
use App\Filament\Resources\ProvenanceSources\Tables\ProvenanceSourcesTable;
use App\Models\ProvenanceSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProvenanceSourceResource extends Resource
{
    protected static ?string $model = ProvenanceSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ProvenanceSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProvenanceSourcesTable::configure($table);
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
            'index' => ListProvenanceSources::route('/'),
            'create' => CreateProvenanceSource::route('/create'),
            'edit' => EditProvenanceSource::route('/{record}/edit'),
        ];
    }
}
