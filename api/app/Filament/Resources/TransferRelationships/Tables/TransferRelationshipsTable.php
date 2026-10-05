<?php

namespace App\Filament\Resources\TransferRelationships\Tables;

use App\Filament\Support\VerificationTableFilters;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransferRelationshipsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('city.name')->searchable(),
                TextColumn::make('fromStop.name')->searchable(),
                TextColumn::make('toStop.name')->searchable(),
                TextColumn::make('relationship_type')->badge(),
                TextColumn::make('distance_meters')->numeric()->suffix(' m'),
                TextColumn::make('provenanceSource.title')->searchable(),
                TextColumn::make('provenanceSource.checked_at')->date()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stable_id')->searchable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('verification_state')->badge()->searchable(),
                IconColumn::make('is_demo')->boolean(),
            ])
            ->filters(VerificationTableFilters::make())
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
