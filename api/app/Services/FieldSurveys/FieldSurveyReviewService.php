<?php

namespace App\Services\FieldSurveys;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimStatus;
use App\Enums\FieldClaimTargetType;
use App\Enums\FieldObservationStatus;
use App\Enums\SurveySessionStatus;
use App\Models\Fare;
use App\Models\FieldEvidenceClaim;
use App\Models\FieldObservation;
use App\Models\FieldReviewAction;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\TransferRelationship;
use App\Models\TransportSurveySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FieldSurveyReviewService
{
    /** @param list<int> $observationIds */
    public function proposeClaim(
        FieldClaimTargetType $targetType,
        string $targetStableId,
        EvidenceClaimType $claimType,
        FieldClaimComparison $comparison,
        string $claimText,
        array $observationIds,
        User $reviewer,
        array $supportingSourceIds = [],
    ): FieldEvidenceClaim {
        $observationIds = array_values(array_unique(array_map('intval', $observationIds)));
        if (trim($claimText) === '' || mb_strlen($claimText) > 2000 || $observationIds === []) {
            throw ValidationException::withMessages(['claim' => 'A concise claim and at least one reviewed observation are required.']);
        }
        if ($this->expectedTarget($claimType) !== $targetType) {
            throw ValidationException::withMessages(['target_type' => 'This evidence scope cannot be attached to the selected record type.']);
        }

        return DB::transaction(function () use ($targetType, $targetStableId, $claimType, $comparison, $claimText, $observationIds, $reviewer, $supportingSourceIds): FieldEvidenceClaim {
            $target = $this->targetQuery($targetType)->where('stable_id', $targetStableId)->lockForUpdate()->first();
            if (! $target instanceof Model) {
                throw ValidationException::withMessages(['target' => 'The selected production record no longer exists.']);
            }
            if ($this->targetIsDemo($targetType, $target)) {
                throw ValidationException::withMessages(['target' => 'Field evidence cannot be attached to demo transport data.']);
            }

            $observations = FieldObservation::query()
                ->with('surveySession:id,is_synthetic,city_id,observer_reference,observer_user_id')
                ->whereIn('id', array_values(array_unique($observationIds)))
                ->lockForUpdate()
                ->get();
            if ($observations->count() !== count(array_unique($observationIds))
                || $observations->contains(fn (FieldObservation $observation): bool => $observation->surveySession?->is_synthetic
                    || $observation->review_status !== FieldObservationStatus::ACCEPTED_AS_EVIDENCE)) {
                throw ValidationException::withMessages(['observations' => 'Claims may use only accepted observations from real surveys.']);
            }
            $targetCityId = $this->targetCityId($targetType, $target);
            if ($targetCityId !== null && $observations->contains(
                fn (FieldObservation $observation): bool => (int) $observation->surveySession?->city_id !== $targetCityId,
            )) {
                throw ValidationException::withMessages(['observations' => 'Field evidence must come from the same city as its production fact.']);
            }

            $supportingSourceIds = array_values(array_unique(array_map('intval', $supportingSourceIds)));
            $supportingSources = ProvenanceSource::query()->whereIn('id', $supportingSourceIds)->get();
            if ($supportingSources->count() !== count($supportingSourceIds)
                || $supportingSources->contains(fn (ProvenanceSource $source): bool => ! in_array($claimType->value, $source->evidence_scope ?? [], true))) {
                throw ValidationException::withMessages(['supporting_source_ids' => 'Every source must exist and explicitly cover this fact scope.']);
            }

            sort($observationIds);
            sort($supportingSourceIds);
            $claimStableId = 'field-claim-'.substr(hash('sha256', json_encode([
                $targetType->value,
                $targetStableId,
                $claimType->value,
                $comparison->value,
                trim($claimText),
                $observationIds,
                $supportingSourceIds,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 0, 32);
            $existingClaim = FieldEvidenceClaim::query()->where('stable_id', $claimStableId)->first();
            if ($existingClaim !== null) {
                return $existingClaim;
            }

            $claim = FieldEvidenceClaim::query()->create([
                'stable_id' => $claimStableId,
                'claim_type' => $claimType,
                'target_type' => $targetType,
                'target_id' => $target->getKey(),
                'target_stable_id' => $targetStableId,
                'comparison' => $comparison,
                'claim_text' => trim($claimText),
                'review_status' => FieldClaimStatus::PROPOSED,
                'proposed_by_user_id' => $reviewer->getKey(),
            ]);
            $claim->observations()->sync($observations->modelKeys());
            $claim->supportingSources()->sync($supportingSourceIds);

            return $claim;
        });
    }

    public function attachClaim(FieldEvidenceClaim $claim, User $reviewer, ?string $reviewNotes = null): FieldEvidenceClaim
    {
        return DB::transaction(function () use ($claim, $reviewer, $reviewNotes): FieldEvidenceClaim {
            $claim = FieldEvidenceClaim::query()->with('observations.surveySession')->lockForUpdate()->findOrFail($claim->getKey());
            if ($claim->review_status !== FieldClaimStatus::PROPOSED) {
                throw ValidationException::withMessages(['claim' => 'Only a proposed claim can be attached.']);
            }
            if ($claim->observations->isEmpty() || $claim->observations->contains(
                fn (FieldObservation $observation): bool => $observation->surveySession?->is_synthetic
                    || $observation->review_status !== FieldObservationStatus::ACCEPTED_AS_EVIDENCE,
            )) {
                throw ValidationException::withMessages(['observations' => 'Every linked observation must be accepted and from a real survey.']);
            }

            $targetType = FieldClaimTargetType::from($claim->target_type->value);
            $target = $this->targetQuery($targetType)->whereKey($claim->target_id)->lockForUpdate()->first();
            if (! $target instanceof Model || $target->stable_id !== $claim->target_stable_id) {
                throw ValidationException::withMessages(['target' => 'Claim target reference no longer matches the production record.']);
            }
            if ($this->targetIsDemo($targetType, $target)) {
                throw ValidationException::withMessages(['target' => 'Field evidence cannot be attached to demo transport data.']);
            }
            $targetCityId = $this->targetCityId($targetType, $target);
            if ($targetCityId !== null && $claim->observations->contains(
                fn (FieldObservation $observation): bool => (int) $observation->surveySession?->city_id !== $targetCityId,
            )) {
                throw ValidationException::withMessages(['observations' => 'Field evidence must come from the same city as its production fact.']);
            }

            $source = ProvenanceSource::query()->create([
                'stable_id' => 'field-source-'.substr(hash('sha256', $claim->stable_id), 0, 32),
                'source_type' => 'DIRECT_OBSERVATION',
                'title' => 'Reviewed field observation evidence',
                'publisher' => null,
                'url' => null,
                'accessed_at' => null,
                'observed_at' => $claim->observations->max('observed_at')?->toDateString(),
                'checked_at' => now()->toDateString(),
                'evidence_scope' => [$claim->claim_type->value],
                'conflict_status' => $claim->comparison === FieldClaimComparison::CONFLICTS_WITH_EXISTING ? 'OPEN' : 'NONE',
                'conflict_group' => $claim->comparison === FieldClaimComparison::CONFLICTS_WITH_EXISTING
                    ? 'field-claim-'.$claim->stable_id
                    : null,
                'verification_method' => 'Field observations accepted as evidence; no production verification decision.',
                'verified_by' => null,
                'notes' => null,
            ]);

            $claim->supportingSources()->syncWithoutDetaching([$source->getKey()]);
            $claim->provenance_source_id = $source->getKey();
            $claim->review_status = FieldClaimStatus::ATTACHED;
            $claim->reviewed_by_user_id = $reviewer->getKey();
            $claim->reviewed_at = now();
            $claim->attached_by_user_id = $reviewer->getKey();
            $claim->attached_at = now();
            $claim->review_notes = $reviewNotes;
            $claim->save();

            $missionClaim = $claim->observations()->with('missionClaim')->first()?->missionClaim;
            FieldReviewAction::query()->create([
                'stable_id' => 'field-review-'.substr(hash('sha256', 'claim|'.$claim->stable_id.'|ATTACHED|'.microtime(true)), 0, 32),
                'field_mission_id' => $missionClaim?->field_mission_id,
                'field_mission_claim_id' => $missionClaim?->getKey(),
                'field_evidence_claim_id' => $claim->getKey(),
                'actor_user_id' => $reviewer->getKey(),
                'action' => 'CLAIM_ATTACHED',
                'from_status' => FieldClaimStatus::PROPOSED->value,
                'to_status' => FieldClaimStatus::ATTACHED->value,
                'reason' => $reviewNotes,
            ]);

            return $claim->fresh(['observations', 'supportingSources', 'provenanceSource']);
        });
    }

    public function rejectClaim(FieldEvidenceClaim $claim, User $reviewer, string $reason): FieldEvidenceClaim
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A brief rejection reason is required.']);
        }

        return DB::transaction(function () use ($claim, $reviewer, $reason): FieldEvidenceClaim {
            $claim = FieldEvidenceClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            if ($claim->review_status !== FieldClaimStatus::PROPOSED) {
                throw ValidationException::withMessages(['claim' => 'Only a proposed claim can be rejected.']);
            }
            $claim->forceFill([
                'review_status' => FieldClaimStatus::REJECTED,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $reason,
            ])->save();

            FieldReviewAction::query()->create([
                'stable_id' => 'field-review-'.substr(hash('sha256', 'claim|'.$claim->stable_id.'|REJECTED|'.microtime(true)), 0, 32),
                'field_evidence_claim_id' => $claim->getKey(),
                'actor_user_id' => $reviewer->getKey(),
                'action' => 'CLAIM_REJECTED',
                'from_status' => FieldClaimStatus::PROPOSED->value,
                'to_status' => FieldClaimStatus::REJECTED->value,
                'reason' => $reason,
            ]);

            return $claim->refresh();
        });
    }

    public function reviewObservation(FieldObservation $observation, FieldObservationStatus $status, User $reviewer, ?string $notes = null): FieldObservation
    {
        if ($status === FieldObservationStatus::REJECTED && trim((string) $notes) === '') {
            throw ValidationException::withMessages(['review_notes' => 'Record a reason when rejecting an observation.']);
        }
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages(['review_notes' => 'Review notes must be at most 2000 characters.']);
        }

        return DB::transaction(function () use ($observation, $status, $reviewer, $notes): FieldObservation {
            $observation = FieldObservation::query()
                ->with('surveySession:id,is_synthetic,field_mission_id')
                ->lockForUpdate()
                ->findOrFail($observation->getKey());
            if ($observation->surveySession?->is_synthetic) {
                throw ValidationException::withMessages(['observation' => 'Synthetic test observations cannot be reviewed as production evidence.']);
            }
            $allowed = match ($observation->review_status) {
                FieldObservationStatus::DRAFT => [FieldObservationStatus::SUBMITTED],
                FieldObservationStatus::SUBMITTED => [FieldObservationStatus::ACCEPTED_AS_EVIDENCE, FieldObservationStatus::REJECTED, FieldObservationStatus::NEEDS_CLARIFICATION],
                FieldObservationStatus::NEEDS_CLARIFICATION => [FieldObservationStatus::SUBMITTED, FieldObservationStatus::ACCEPTED_AS_EVIDENCE, FieldObservationStatus::REJECTED],
                default => [],
            };
            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['review_status' => 'This field-observation review transition is not allowed.']);
            }

            $fromStatus = (string) $observation->getRawOriginal('review_status');
            $observation->forceFill([
                'review_status' => $status,
                'reviewed_by_user_id' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();

            FieldReviewAction::query()->create([
                'stable_id' => 'field-review-'.substr(hash('sha256', 'observation|'.$observation->stable_id.'|'.$status->value.'|'.microtime(true)), 0, 32),
                'field_mission_id' => $observation->surveySession?->field_mission_id,
                'field_observation_id' => $observation->getKey(),
                'actor_user_id' => $reviewer->getKey(),
                'action' => 'OBSERVATION_REVIEWED',
                'from_status' => $fromStatus,
                'to_status' => $status->value,
                'reason' => $notes,
            ]);

            return $observation->refresh();
        });
    }

    public function updateSessionStatus(TransportSurveySession $session, SurveySessionStatus $status, User $reviewer): TransportSurveySession
    {

        return DB::transaction(function () use ($session, $status, $reviewer): TransportSurveySession {
            $session = TransportSurveySession::query()->lockForUpdate()->findOrFail($session->getKey());
            if ($session->is_synthetic) {
                throw ValidationException::withMessages(['session' => 'Synthetic test sessions cannot enter the production review lifecycle.']);
            }
            $allowed = match ($session->status) {
                SurveySessionStatus::PLANNED => [SurveySessionStatus::IN_PROGRESS, SurveySessionStatus::ABORTED],
                SurveySessionStatus::IN_PROGRESS => [SurveySessionStatus::COMPLETED, SurveySessionStatus::ABORTED],
                SurveySessionStatus::COMPLETED, SurveySessionStatus::ABORTED => [SurveySessionStatus::UNDER_REVIEW],
                SurveySessionStatus::UNDER_REVIEW => [SurveySessionStatus::REVIEWED],
                default => [],
            };
            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'This survey-session lifecycle transition is not allowed.']);
            }
            if ($status === SurveySessionStatus::REVIEWED && $session->observations()->whereNotIn('review_status', [
                FieldObservationStatus::ACCEPTED_AS_EVIDENCE->value,
                FieldObservationStatus::REJECTED->value,
            ])->exists()) {
                throw ValidationException::withMessages(['status' => 'Review every submitted observation before closing the survey.']);
            }
            $fromStatus = (string) $session->getRawOriginal('status');
            $session->forceFill([
                'status' => $status,
            ]);

            if ($status === SurveySessionStatus::IN_PROGRESS) {
                $session->started_at ??= now();
                $session->ended_at = null;
                $session->observer_user_id ??= $reviewer->getKey();
            }

            if ($status === SurveySessionStatus::COMPLETED) {
                $session->started_at ??= now();
                $session->ended_at = now();
            }

            if ($status === SurveySessionStatus::ABORTED && $session->started_at !== null) {
                $session->ended_at ??= now();
            }

            if (in_array($status, [SurveySessionStatus::UNDER_REVIEW, SurveySessionStatus::REVIEWED], true)) {
                $session->reviewed_by_user_id = $reviewer->getKey();
                $session->reviewed_at = $status === SurveySessionStatus::REVIEWED ? now() : null;
            }

            $session->save();

            FieldReviewAction::query()->create([
                'stable_id' => 'field-review-'.substr(hash('sha256', 'session|'.$session->stable_id.'|'.$status->value.'|'.microtime(true)), 0, 32),
                'field_mission_id' => $session->field_mission_id,
                'actor_user_id' => $reviewer->getKey(),
                'action' => 'SESSION_REVIEWED',
                'from_status' => $fromStatus,
                'to_status' => $status->value,
            ]);

            return $session->refresh();
        });
    }

    private function expectedTarget(EvidenceClaimType $claimType): FieldClaimTargetType
    {
        return match ($claimType) {
            EvidenceClaimType::PLACE_LOCATION => FieldClaimTargetType::PLACE,
            EvidenceClaimType::STOP_LOCATION, EvidenceClaimType::BOARDING => FieldClaimTargetType::STOP,
            EvidenceClaimType::ROUTE_IDENTITY => FieldClaimTargetType::ROUTE,
            EvidenceClaimType::ROUTE_PATTERN, EvidenceClaimType::ROUTE_GEOMETRY => FieldClaimTargetType::ROUTE_PATTERN,
            EvidenceClaimType::PATTERN_STOP_SERVICE,
            EvidenceClaimType::PATTERN_STOP_SEQUENCE,
            EvidenceClaimType::PATTERN_STOP_BOARDING,
            EvidenceClaimType::PATTERN_STOP_ALIGHTING => FieldClaimTargetType::PATTERN_STOP,
            EvidenceClaimType::TRANSFER => FieldClaimTargetType::TRANSFER,
            EvidenceClaimType::FARE, EvidenceClaimType::FARE_GENERAL_TRADITIONAL_PUJ => FieldClaimTargetType::FARE,
        };
    }

    private function targetQuery(FieldClaimTargetType $type): Builder
    {
        $class = match ($type) {
            FieldClaimTargetType::PLACE => Place::class,
            FieldClaimTargetType::STOP => Stop::class,
            FieldClaimTargetType::ROUTE => Route::class,
            FieldClaimTargetType::ROUTE_PATTERN => RoutePattern::class,
            FieldClaimTargetType::PATTERN_STOP => RoutePatternStop::class,
            FieldClaimTargetType::TRANSFER => TransferRelationship::class,
            FieldClaimTargetType::FARE => Fare::class,
        };

        return $class::query();
    }

    private function targetCityId(FieldClaimTargetType $type, Model $target): ?int
    {
        return match ($type) {
            FieldClaimTargetType::PLACE, FieldClaimTargetType::STOP, FieldClaimTargetType::ROUTE => (int) $target->city_id,
            FieldClaimTargetType::ROUTE_PATTERN => (int) $target->route()->value('city_id'),
            FieldClaimTargetType::PATTERN_STOP => (int) $target->routePattern()->first()?->route()->value('city_id'),
            FieldClaimTargetType::TRANSFER => (int) $target->city_id,
            FieldClaimTargetType::FARE => $target->route_id === null ? null : (int) $target->route()->value('city_id'),
        };
    }

    private function targetIsDemo(FieldClaimTargetType $type, Model $target): bool
    {
        if ((bool) $target->getAttribute('is_demo')) {
            return true;
        }

        return match ($type) {
            FieldClaimTargetType::ROUTE_PATTERN => (bool) $target->route()->first()?->is_demo,
            FieldClaimTargetType::PATTERN_STOP => $this->patternStopIsDemo($target),
            FieldClaimTargetType::TRANSFER => $this->transferIsDemo($target),
            default => false,
        };
    }

    private function patternStopIsDemo(Model $target): bool
    {
        $pattern = $target->routePattern()->with('route')->first();
        $stop = $target->stop()->first();

        return (bool) $pattern?->is_demo || (bool) $pattern?->route?->is_demo || (bool) $stop?->is_demo;
    }

    private function transferIsDemo(Model $target): bool
    {
        $fromStop = $target->fromStop()->first();
        $toStop = $target->toStop()->first();
        $fromPattern = $target->fromRoutePattern()->with('route')->first();
        $toPattern = $target->toRoutePattern()->with('route')->first();

        return (bool) $fromStop?->is_demo
            || (bool) $toStop?->is_demo
            || (bool) $fromPattern?->is_demo
            || (bool) $fromPattern?->route?->is_demo
            || (bool) $toPattern?->is_demo
            || (bool) $toPattern?->route?->is_demo;
    }
}
