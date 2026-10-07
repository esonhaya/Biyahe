<?php

namespace App\Filament\Resources\FieldReviewActions;

use App\Filament\Resources\FieldReviewActions\Pages\ManageFieldReviewActions;
use App\Models\FieldReviewAction;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class FieldReviewActionResource extends Resource
{
    protected static ?string $model = FieldReviewAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $navigationLabel = 'Field audit trail';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('stable_id'),
            TextEntry::make('action')->badge(),
            TextEntry::make('field_mission_id'),
            TextEntry::make('field_observation_id'),
            TextEntry::make('field_evidence_claim_id'),
            TextEntry::make('from_status'),
            TextEntry::make('to_status'),
            TextEntry::make('reason')->columnSpanFull(),
            TextEntry::make('before_snapshot')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'null')->columnSpanFull(),
            TextEntry::make('after_snapshot')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'null')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->latest('id'))
            ->columns([
                TextColumn::make('action')->badge()->sortable(),
                TextColumn::make('field_mission_id')->label('Mission')->sortable(),
                TextColumn::make('field_observation_id')->label('Observation')->sortable(),
                TextColumn::make('field_evidence_claim_id')->label('Claim')->sortable(),
                TextColumn::make('from_status'),
                TextColumn::make('to_status'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFieldReviewActions::route('/')];
    }
}
