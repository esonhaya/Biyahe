<?php

namespace App\Filament\Resources\Fares\Tables;

use App\Filament\Support\VerificationTableFilters;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FaresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('route.name')
                    ->searchable(),
                TextColumn::make('vehicleType.name')
                    ->searchable(),
                TextColumn::make('routePattern.name')
                    ->searchable(),
                TextColumn::make('originStop.name')
                    ->searchable(),
                TextColumn::make('destinationStop.name')
                    ->searchable(),
                TextColumn::make('provenanceSource.title')
                    ->searchable(),
                TextColumn::make('provenanceSource.checked_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stable_id')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fare_class')
                    ->searchable(),
                TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('currency')
                    ->searchable(),
                TextColumn::make('min_distance_km')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('max_distance_km')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('effective_until')
                    ->date()
                    ->sortable(),
                TextColumn::make('verification_state')
                    ->badge()
                    ->searchable(),
                IconColumn::make('is_demo')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
