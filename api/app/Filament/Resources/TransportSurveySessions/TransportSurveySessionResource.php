<?php

namespace App\Filament\Resources\TransportSurveySessions;

use App\Enums\SurveySessionStatus;
use App\Filament\Resources\TransportSurveySessions\Pages\ManageTransportSurveySessions;
use App\Models\TransportSurveySession;
use App\Services\FieldSurveys\FieldSurveyReviewService;
use App\Services\FieldSurveys\FieldSurveyTraceReviewData;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransportSurveySessionResource extends Resource
{
    protected static ?string $model = TransportSurveySession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title'),
                TextEntry::make('status')->badge(),
                TextEntry::make('purpose')->columnSpanFull(),
                TextEntry::make('observer_reference')->label('Observer reference'),
                TextEntry::make('route.code')->label('Route'),
                TextEntry::make('routePattern.direction')->label('Direction'),
                TextEntry::make('started_at')->dateTime(),
                TextEntry::make('ended_at')->dateTime(),
                TextEntry::make('notes')->columnSpanFull(),
                TextEntry::make('survey_trace_review')
                    ->label('Ordered field evidence (points are not snapped to roads)')
                    ->state(fn (TransportSurveySession $record): string => json_encode([
                        'observations' => $record->observations->map(fn ($observation): array => [
                            'sequence' => $observation->sequence,
                            'type' => $observation->observation_type->value,
                            'observed_at' => $observation->observed_at?->toIso8601String(),
                            'point' => $observation->location_point,
                            'accuracy_m' => $observation->reported_accuracy_m,
                            'precision' => $observation->location_precision->value,
                            'fact' => $observation->observed_fact,
                        ])->all(),
                        'trace_points' => $record->tracePoints->map(fn ($point): array => [
                            'sequence' => $point->sequence,
                            'observed_at' => $point->observed_at?->toIso8601String(),
                            'point' => $point->location_point,
                            'accuracy_m' => $point->reported_accuracy_m,
                            'precision' => $point->location_precision->value,
                        ])->all(),
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}')
                    ->columnSpanFull(),
                ViewEntry::make('survey_trace_map')
                    ->label('Survey trace review')
                    ->view('filament.field-survey-trace')
                    ->viewData(fn (TransportSurveySession $record): array => app(FieldSurveyTraceReviewData::class)->forSession($record))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['route', 'routePattern']))
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('route.code')->label('Route')->placeholder('—'),
                TextColumn::make('routePattern.direction')->label('Direction')->placeholder('—'),
                TextColumn::make('observer_reference')->label('Observer')->searchable(),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('observations_count')->counts('observations')->label('Observations'),
                TextColumn::make('trace_points_count')->counts('tracePoints')->label('Trace points'),
                TextColumn::make('is_synthetic')->badge()->label('Synthetic test data'),
            ])
            ->filters([])
            ->recordActions([
                ViewAction::make(),
                Action::make('startReview')
                    ->label('Start review')
                    ->requiresConfirmation()
                    ->visible(fn (TransportSurveySession $record): bool => ! $record->is_synthetic && in_array($record->status->value, ['COMPLETED', 'ABORTED'], true))
                    ->action(fn (TransportSurveySession $record) => app(FieldSurveyReviewService::class)
                        ->updateSessionStatus($record, SurveySessionStatus::UNDER_REVIEW, auth()->user())),
                Action::make('finishReview')
                    ->label('Finish review')
                    ->requiresConfirmation()
                    ->visible(fn (TransportSurveySession $record): bool => ! $record->is_synthetic && $record->status->value === 'UNDER_REVIEW')
                    ->action(fn (TransportSurveySession $record) => app(FieldSurveyReviewService::class)
                        ->updateSessionStatus($record, SurveySessionStatus::REVIEWED, auth()->user())),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTransportSurveySessions::route('/'),
        ];
    }
}
