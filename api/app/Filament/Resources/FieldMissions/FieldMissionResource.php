<?php

namespace App\Filament\Resources\FieldMissions;

use App\Enums\FieldMissionStatus;
use App\Filament\Resources\FieldMissions\Pages\ManageFieldMissions;
use App\Models\FieldMission;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class FieldMissionResource extends Resource
{
    protected static ?string $model = FieldMission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Field missions';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('stable_id'),
            TextEntry::make('title'),
            TextEntry::make('area'),
            TextEntry::make('status')->badge(),
            TextEntry::make('priority'),
            TextEntry::make('route_scope')->formatStateUsing(fn ($state): string => implode(', ', $state ?? [])),
            TextEntry::make('instructions')->columnSpanFull(),
            TextEntry::make('safety_notes')->columnSpanFull(),
            TextEntry::make('claims')
                ->label('Claim checklist')
                ->state(fn (FieldMission $record): string => json_encode($record->claims->map(fn ($claim): array => [
                    'claim_id' => $claim->stable_id,
                    'type' => $claim->claim_type,
                    'target' => $claim->target_stable_id,
                    'status' => $claim->current_status?->value ?? $claim->current_status,
                    'prompt' => $claim->prompt,
                ])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['claims', 'sessions']))
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('area')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('priority')->numeric()->sortable(),
                TextColumn::make('claims_count')->label('Claims'),
                TextColumn::make('sessions_count')->label('Sessions'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(FieldMissionStatus::values(), FieldMissionStatus::values())),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFieldMissions::route('/')];
    }
}
