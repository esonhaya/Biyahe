<?php

namespace App\Filament\Resources\FieldObservations;

use App\Enums\FieldObservationStatus;
use App\Filament\Resources\FieldObservations\Pages\ManageFieldObservations;
use App\Models\FieldObservation;
use App\Services\FieldSurveys\FieldSurveyReviewService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class FieldObservationResource extends Resource
{
    protected static ?string $model = FieldObservation::class;

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
                TextEntry::make('surveySession.title')->label('Survey'),
                TextEntry::make('sequence'),
                TextEntry::make('observation_type')->badge(),
                TextEntry::make('review_status')->badge(),
                TextEntry::make('route.code')->label('Route'),
                TextEntry::make('routePattern.direction')->label('Direction'),
                TextEntry::make('observed_at')->dateTime(),
                TextEntry::make('location_point')->label('Observed coordinate (longitude, latitude)'),
                TextEntry::make('reported_accuracy_m')->label('Reported device accuracy (m)'),
                TextEntry::make('location_precision')->label('Location precision category')->badge(),
                TextEntry::make('observed_fact')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')->columnSpanFull(),
                TextEntry::make('notes')->columnSpanFull(),
                TextEntry::make('supporting_references')->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]')->columnSpanFull(),
                TextEntry::make('review_notes')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['surveySession', 'route', 'routePattern'])
                ->when(config('database.default') === 'pgsql', fn ($builder) => $builder->addSelect([
                    'field_observations.*',
                    DB::raw('ST_AsText(field_observations.location) AS location_wkt'),
                ])))
            ->columns([
                TextColumn::make('stable_id')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('surveySession.title')->label('Survey')->searchable()->wrap(),
                TextColumn::make('sequence')->numeric()->sortable(),
                TextColumn::make('observation_type')->badge()->sortable(),
                TextColumn::make('review_status')->badge()->sortable(),
                TextColumn::make('route.code')->label('Route'),
                TextColumn::make('routePattern.direction')->label('Direction'),
                TextColumn::make('observed_at')->dateTime()->sortable(),
                TextColumn::make('location_point')->label('Coordinate')->state(fn (FieldObservation $record): string => $record->location_point === null
                    ? 'No point'
                    : sprintf('%.6f, %.6f', $record->location_point['lng'], $record->location_point['lat'])),
                TextColumn::make('reported_accuracy_m')->label('Accuracy m'),
                TextColumn::make('surveySession.is_synthetic')->label('Synthetic')->badge(),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make(),
                Action::make('submit')
                    ->label('Submit observation')
                    ->requiresConfirmation()
                    ->visible(fn (FieldObservation $record): bool => ! $record->surveySession?->is_synthetic && $record->review_status === FieldObservationStatus::DRAFT)
                    ->action(fn (FieldObservation $record) => app(FieldSurveyReviewService::class)
                        ->reviewObservation($record, FieldObservationStatus::SUBMITTED, auth()->user())),
                Action::make('acceptEvidence')
                    ->label('Accept as evidence')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FieldObservation $record): bool => ! $record->surveySession?->is_synthetic && in_array($record->review_status, [FieldObservationStatus::SUBMITTED, FieldObservationStatus::NEEDS_CLARIFICATION], true))
                    ->form([Textarea::make('review_notes')->maxLength(2000)])
                    ->action(fn (FieldObservation $record, array $data) => app(FieldSurveyReviewService::class)
                        ->reviewObservation($record, FieldObservationStatus::ACCEPTED_AS_EVIDENCE, auth()->user(), $data['review_notes'] ?? null)),
                Action::make('needsClarification')
                    ->label('Request clarification')
                    ->color('warning')
                    ->visible(fn (FieldObservation $record): bool => ! $record->surveySession?->is_synthetic && in_array($record->review_status, [FieldObservationStatus::SUBMITTED, FieldObservationStatus::NEEDS_CLARIFICATION], true))
                    ->form([Textarea::make('review_notes')->required()->maxLength(2000)])
                    ->action(fn (FieldObservation $record, array $data) => app(FieldSurveyReviewService::class)
                        ->reviewObservation($record, FieldObservationStatus::NEEDS_CLARIFICATION, auth()->user(), $data['review_notes'])),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->visible(fn (FieldObservation $record): bool => ! $record->surveySession?->is_synthetic && in_array($record->review_status, [FieldObservationStatus::SUBMITTED, FieldObservationStatus::NEEDS_CLARIFICATION], true))
                    ->form([Textarea::make('review_notes')->required()->maxLength(2000)])
                    ->action(fn (FieldObservation $record, array $data) => app(FieldSurveyReviewService::class)
                        ->reviewObservation($record, FieldObservationStatus::REJECTED, auth()->user(), $data['review_notes'])),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFieldObservations::route('/'),
        ];
    }
}
