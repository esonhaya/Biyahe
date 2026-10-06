<?php

namespace App\Filament\Resources\FieldEvidenceClaims\Pages;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimTargetType;
use App\Enums\FieldObservationStatus;
use App\Filament\Resources\FieldEvidenceClaims\FieldEvidenceClaimResource;
use App\Models\FieldObservation;
use App\Models\ProvenanceSource;
use App\Services\FieldSurveys\FieldSurveyReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\MultiSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;

class ManageFieldEvidenceClaims extends ManageRecords
{
    protected static string $resource = FieldEvidenceClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('proposeClaim')
                ->label('Propose fact claim')
                ->form([
                    Select::make('target_type')->options(collect(FieldClaimTargetType::cases())->mapWithKeys(fn ($type): array => [$type->value => str($type->value)->headline()->toString()]))->required(),
                    TextInput::make('target_stable_id')->required()->maxLength(150),
                    Select::make('claim_type')->options(collect(EvidenceClaimType::cases())->mapWithKeys(fn ($type): array => [$type->value => str($type->value)->headline()->toString()]))->required(),
                    Select::make('comparison')->options(collect(FieldClaimComparison::cases())->mapWithKeys(fn ($comparison): array => [$comparison->value => str($comparison->value)->headline()->toString()]))->required(),
                    Textarea::make('claim_text')->required()->maxLength(2000)->columnSpanFull(),
                    MultiSelect::make('observation_ids')
                        ->label('Accepted real observations')
                        ->options(fn (): array => FieldObservation::query()
                            ->where('review_status', FieldObservationStatus::ACCEPTED_AS_EVIDENCE->value)
                            ->whereHas('surveySession', fn ($query) => $query->where('is_synthetic', false))
                            ->orderByDesc('observed_at')
                            ->limit(200)
                            ->get(['id', 'stable_id', 'observed_at'])
                            ->mapWithKeys(fn (FieldObservation $observation): array => [$observation->id => $observation->stable_id.' · '.$observation->observed_at?->toDateTimeString()])
                            ->all())
                        ->searchable()
                        ->required()
                        ->columnSpanFull(),
                    MultiSelect::make('supporting_source_ids')
                        ->label('Additional fact-scoped sources (optional)')
                        ->options(fn (): array => ProvenanceSource::query()
                            ->whereNotNull('evidence_scope')
                            ->orderByDesc('checked_at')
                            ->limit(200)
                            ->get(['id', 'title', 'source_type', 'checked_at'])
                            ->mapWithKeys(fn (ProvenanceSource $source): array => [$source->id => $source->title.' · '.$source->source_type.' · '.($source->checked_at?->toDateString() ?? 'date unknown')])
                            ->all())
                        ->searchable()
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    app(FieldSurveyReviewService::class)->proposeClaim(
                        FieldClaimTargetType::from($data['target_type']),
                        $data['target_stable_id'],
                        EvidenceClaimType::from($data['claim_type']),
                        FieldClaimComparison::from($data['comparison']),
                        $data['claim_text'],
                        array_map('intval', $data['observation_ids']),
                        auth()->user(),
                        array_map('intval', $data['supporting_source_ids'] ?? []),
                    );
                }),
        ];
    }
}
