<?php

namespace App\Filament\Resources\RoutePatterns\RelationManagers;

use Filament\Actions\AssociateAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DissociateAction;
use Filament\Actions\DissociateBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PatternStopsRelationManager extends RelationManager
{
    protected static string $relationship = 'patternStops';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('stop_id')
                    ->relationship('stop', 'name')
                    ->required(),
                Select::make('provenance_source_id')
                    ->relationship('provenanceSource', 'title'),
                TextInput::make('sequence')
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1),
                Toggle::make('boarding_allowed')
                    ->required(),
                Toggle::make('alighting_allowed')
                    ->required(),
                Textarea::make('instruction')
                    ->columnSpanFull(),
                Textarea::make('service_metadata')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence')
            ->columns([
                TextColumn::make('stop.name')
                    ->searchable(),
                TextColumn::make('sequence')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('boarding_allowed')
                    ->boolean(),
                IconColumn::make('alighting_allowed')
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
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
                AssociateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DissociateAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DissociateBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
