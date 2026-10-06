<?php

namespace App\Services\FieldSurveys;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimStatus;
use App\Enums\FieldObservationStatus;
use App\Models\FieldEvidenceClaim;
use App\Models\FieldObservation;
use App\Models\TransportSurveySession;
use Illuminate\Support\Collection;

final class FieldSurveyReport
{
    /** @param array<string, mixed>|null $priorityAnalysis @return array<string, mixed> */
    public function report(?array $priorityAnalysis = null): array
    {
        $realSessions = TransportSurveySession::query()->where('is_synthetic', false);
        $sessions = (clone $realSessions)->get(['id', 'stable_id', 'status']);
        $observations = FieldObservation::query()
            ->whereHas('surveySession', fn ($query) => $query->where('is_synthetic', false))
            ->get(['id', 'survey_session_id', 'review_status']);
        $claims = FieldEvidenceClaim::query()
            ->with([
                'observations.surveySession:id,is_synthetic,observer_reference,observer_user_id',
                'supportingSources:id,stable_id,title,source_type,conflict_status',
            ])
            ->whereHas('observations.surveySession', fn ($query) => $query->where('is_synthetic', false))
            ->get();
        $syntheticClaims = FieldEvidenceClaim::query()
            ->whereHas('observations.surveySession', fn ($query) => $query->where('is_synthetic', true))
            ->count();
        $syntheticSessions = TransportSurveySession::query()->where('is_synthetic', true)->get(['status']);
        $syntheticObservations = FieldObservation::query()
            ->whereHas('surveySession', fn ($query) => $query->where('is_synthetic', true))
            ->get(['review_status']);
        $realClaims = $claims->filter(fn (FieldEvidenceClaim $claim): bool => $claim->observations->isNotEmpty()
            && $claim->observations->every(fn (FieldObservation $observation): bool => ! $observation->surveySession?->is_synthetic));
        $attached = $realClaims->filter(fn (FieldEvidenceClaim $claim): bool => $claim->review_status === FieldClaimStatus::ATTACHED);
        $supportedGroups = $attached->groupBy(fn (FieldEvidenceClaim $claim): string => implode(':', [
            $claim->target_type->value,
            $claim->target_id,
            $claim->claim_type->value,
        ]));
        $corroborated = $supportedGroups->filter(fn (Collection $group): bool => $group
            ->flatMap(fn (FieldEvidenceClaim $claim) => $claim->observations)
            ->unique('id')
            ->count() > 1);
        $supportedObservationCount = $attached->flatMap(fn (FieldEvidenceClaim $claim) => $claim->observations)->unique('id')->count();
        $supportedFactScopes = [];
        foreach ($attached as $claim) {
            $supportedFactScopes[$claim->target_stable_id][$claim->claim_type->value] = true;
        }
        $priorityItems = array_values($priorityAnalysis['items'] ?? []);
        $unsurveyedPriorityItems = array_values(array_filter($priorityItems, function (array $item) use ($supportedFactScopes): bool {
            $stableId = $item['stable_id'] ?? null;
            if (! is_string($stableId)) {
                return true;
            }

            foreach ($this->claimScopesForPriorityItem($item) as $scope) {
                if (isset($supportedFactScopes[$stableId][$scope])) {
                    return false;
                }
            }

            return true;
        }));
        $claimDetails = $realClaims->map(fn (FieldEvidenceClaim $claim): array => [
            'claim' => $claim->stable_id,
            'target_type' => $claim->target_type->value,
            'target_stable_id' => $claim->target_stable_id,
            'fact' => $claim->claim_type->value,
            'comparison' => $claim->comparison->value,
            'review_status' => $claim->review_status->value,
            'observations' => $claim->observations->map(fn (FieldObservation $observation): array => [
                'stable_id' => $observation->stable_id,
                'observed_at' => $observation->observed_at?->toIso8601String(),
            ])->values()->all(),
            'independent_observers' => $claim->observations
                ->map(fn (FieldObservation $observation): string => $observation->surveySession?->observer_user_id
                    ? 'user:'.$observation->surveySession->observer_user_id
                    : 'reference:'.trim((string) $observation->surveySession?->observer_reference))
                ->filter(fn (string $reference): bool => ! str_ends_with($reference, ':'))
                ->unique()
                ->count(),
            'supporting_sources' => $claim->supportingSources->map(fn ($source): array => [
                'stable_id' => $source->stable_id,
                'title' => $source->title,
                'type' => $source->source_type,
                'conflict_status' => $source->conflict_status?->value ?? $source->conflict_status,
            ])->values()->all(),
            'conflicting_evidence' => $claim->comparison === FieldClaimComparison::CONFLICTS_WITH_EXISTING
                ? [$claim->stable_id]
                : [],
            'latest_observation' => $claim->observations->max('observed_at')?->toIso8601String(),
        ])->values()->all();

        return [
            'metric_scope' => 'REAL_ONLY',
            'surveys_total' => $sessions->count(),
            'surveys_planned' => $sessions->filter(fn (TransportSurveySession $session): bool => $session->status->value === 'PLANNED')->count(),
            'surveys_completed' => $sessions->filter(fn (TransportSurveySession $session): bool => $session->status->value === 'COMPLETED')->count(),
            'surveys_under_review' => $sessions->filter(fn (TransportSurveySession $session): bool => $session->status->value === 'UNDER_REVIEW')->count(),
            'observations_total' => $observations->count(),
            'observations_accepted' => $observations->filter(fn (FieldObservation $observation): bool => $observation->review_status === FieldObservationStatus::ACCEPTED_AS_EVIDENCE)->count(),
            'observations_rejected' => $observations->filter(fn (FieldObservation $observation): bool => $observation->review_status === FieldObservationStatus::REJECTED)->count(),
            'field_claims_total' => $realClaims->count(),
            'field_claims_supporting_existing' => $realClaims->filter(fn (FieldEvidenceClaim $claim): bool => $claim->comparison === FieldClaimComparison::SUPPORTS_EXISTING)->count(),
            'field_claims_conflicting' => $realClaims->filter(fn (FieldEvidenceClaim $claim): bool => $claim->comparison === FieldClaimComparison::CONFLICTS_WITH_EXISTING)->count(),
            'field_claims_new_information' => $realClaims->filter(fn (FieldEvidenceClaim $claim): bool => $claim->comparison === FieldClaimComparison::ADDS_NEW_INFORMATION)->count(),
            'facts_with_field_support' => $supportedGroups->count(),
            'facts_with_multiple_field_observations' => $corroborated->count(),
            'accepted_observations_in_attached_claims' => $supportedObservationCount,
            'survey_priority_items' => count($priorityItems),
            'high_impact_unsurveyed_fact_count' => count($unsurveyedPriorityItems),
            'high_impact_unsurveyed_facts' => array_slice($unsurveyedPriorityItems, 0, 20),
            'synthetic_metrics' => [
                'surveys_total' => $syntheticSessions->count(),
                'surveys_planned' => $syntheticSessions->filter(fn (TransportSurveySession $session): bool => $session->status->value === 'PLANNED')->count(),
                'surveys_completed' => $syntheticSessions->filter(fn (TransportSurveySession $session): bool => $session->status->value === 'COMPLETED')->count(),
                'surveys_under_review' => $syntheticSessions->filter(fn (TransportSurveySession $session): bool => $session->status->value === 'UNDER_REVIEW')->count(),
                'observations_total' => $syntheticObservations->count(),
                'observations_accepted' => $syntheticObservations->filter(fn (FieldObservation $observation): bool => $observation->review_status === FieldObservationStatus::ACCEPTED_AS_EVIDENCE)->count(),
                'observations_rejected' => $syntheticObservations->filter(fn (FieldObservation $observation): bool => $observation->review_status === FieldObservationStatus::REJECTED)->count(),
                'claims_with_synthetic_observations' => $syntheticClaims,
            ],
            'claims' => $claimDetails,
        ];
    }

    /** @param array<string, mixed> $item @return list<string> */
    private function claimScopesForPriorityItem(array $item): array
    {
        return match ($item['record_type'] ?? null) {
            'transport_model' => [EvidenceClaimType::ROUTE_PATTERN->value, EvidenceClaimType::ROUTE_GEOMETRY->value],
            'route_pattern_stop' => isset($item['fact']) && in_array($item['fact'], ['service', 'sequence', 'boarding', 'alighting'], true)
                ? ['PATTERN_STOP_'.strtoupper($item['fact'])]
                : [],
            'stop' => [EvidenceClaimType::BOARDING->value],
            'route' => [EvidenceClaimType::ROUTE_IDENTITY->value],
            'place' => [EvidenceClaimType::PLACE_LOCATION->value],
            'transfer_relationship' => [EvidenceClaimType::TRANSFER->value],
            'fare' => [EvidenceClaimType::FARE->value, EvidenceClaimType::FARE_GENERAL_TRADITIONAL_PUJ->value],
            default => [],
        };
    }
}
