<?php

namespace App\Filament\Resources\FieldEvidenceClaims;

use App\Enums\FieldClaimStatus;
use App\Filament\Resources\FieldEvidenceClaims\Pages\ManageFieldEvidenceClaims;
use App\Models\FieldEvidenceClaim;
use App\Services\FieldSurveys\FieldSurveyReviewService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FieldEvidenceClaimResource extends Resource
{
    protected static ?string $model = FieldEvidenceClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('stable_id'),
                TextEntry::make('claim_type')->badge(),
                TextEntry::make('target_type')->badge(),
                TextEntry::make('target_stable_id'),
                TextEntry::make('comparison')->badge(),
                TextEntry::make('review_status')->badge(),
                TextEntry::make('claim_text')->columnSpanFull(),
                TextEntry::make('observations')
                    ->label('Linked observations')
                    ->state(fn (FieldEvidenceClaim $record): string => json_encode($record->observations->map(fn ($observation): array => [
                        'stable_id' => $observation->stable_id,
                        'status' => $observation->review_status->value,
                        'sequence' => $observation->sequence,
                        'observed_at' => $observation->observed_at?->toIso8601String(),
                        'fact' => $observation->observed_fact,
                        'point' => $observation->location_point,
                        'reported_accuracy_m' => $observation->reported_accuracy_m,
                    ])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]')
                    ->columnSpanFull(),
                TextEntry::make('supporting_sources')
                    ->label('Source records')
                    ->state(fn (FieldEvidenceClaim $record): string => $record->supportingSources->pluck('title')->implode(', ')),
                TextEntry::make('provenanceSource.checked_at')->label('Evidence checked')->date(),
                TextEntry::make('review_notes')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['observations', 'supportingSources', 'provenanceSource'])->withCount(['observations', 'supportingSources']))
            ->columns([
                TextColumn::make('stable_id')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('claim_type')->badge()->sortable(),
                TextColumn::make('target_type')->badge()->sortable(),
                TextColumn::make('target_stable_id')->searchable(),
                TextColumn::make('comparison')->badge()->sortable(),
                TextColumn::make('review_status')->badge()->sortable(),
                TextColumn::make('observations_count')->label('Observations'),
                TextColumn::make('supporting_sources_count')->label('Sources'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make(),
                Action::make('attachEvidence')
                    ->label('Attach reviewed evidence')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FieldEvidenceClaim $record): bool => $record->review_status === FieldClaimStatus::PROPOSED)
                    ->action(fn (FieldEvidenceClaim $record) => app(FieldSurveyReviewService::class)
                        ->attachClaim($record, auth()->user())),
                Action::make('rejectClaim')
                    ->label('Reject claim')
                    ->color('danger')
                    ->visible(fn (FieldEvidenceClaim $record): bool => $record->review_status === FieldClaimStatus::PROPOSED)
                    ->form([Textarea::make('reason')->required()->maxLength(2000)])
                    ->action(fn (FieldEvidenceClaim $record, array $data) => app(FieldSurveyReviewService::class)
                        ->rejectClaim($record, auth()->user(), $data['reason'])),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFieldEvidenceClaims::route('/'),
        ];
    }
}
