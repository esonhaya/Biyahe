<?php

namespace App\Services\FieldSurveys;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimStatus;
use App\Enums\FieldClaimTargetType;
use App\Enums\FieldObservationStatus;
use App\Models\FieldEvidenceClaim;
use App\Models\FieldReviewAction;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\TransferRelationship;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FieldPromotionService
{
    /** @return array<string, mixed> */
    public function preview(FieldEvidenceClaim $claim): array
    {
        $claim = $this->loadClaim($claim);
        $target = $this->target($claim);
        $old = $target === null ? null : $this->snapshot($target);
        $proposed = $this->proposed($claim, $target);
        $eligible = $claim->review_status === FieldClaimStatus::ATTACHED
            && $claim->comparison !== FieldClaimComparison::CONFLICTS_WITH_EXISTING
            && $claim->observations->isNotEmpty()
            && $claim->observations->every(fn ($observation): bool => $observation->review_status === FieldObservationStatus::ACCEPTED_AS_EVIDENCE && ! $observation->surveySession?->is_synthetic)
            && ($proposed['status'] ?? null) !== 'NO_SAFE_SCOPED_MUTATION';

        return [
            'eligible' => $eligible,
            'claim' => [
                'stable_id' => $claim->stable_id,
                'type' => $claim->claim_type?->value ?? $claim->claim_type,
                'target' => $claim->target_stable_id,
                'status' => $claim->review_status?->value ?? $claim->review_status,
            ],
            'current_fact' => $old,
            'proposed_change' => $proposed,
            'scope_note' => 'Only the named fact scope changes. Unobserved stops, sequence relations, fares, geometry, transfers, and route trust remain unchanged.',
        ];
    }

    public function promote(FieldEvidenceClaim $claim, User $reviewer, ?string $reason = null): FieldReviewAction
    {
        return DB::transaction(function () use ($claim, $reviewer, $reason): FieldReviewAction {
            $claim = $this->loadClaim($claim, true);
            $preview = $this->preview($claim);
            if (! $preview['eligible']) {
                throw ValidationException::withMessages(['claim' => 'Only an attached, non-conflicting claim backed by accepted real observations can be promoted.']);
            }
            $target = $this->target($claim, true);
            if (! $target instanceof Model) {
                throw ValidationException::withMessages(['target' => 'The production target no longer exists.']);
            }
            if ((bool) $target->getAttribute('is_demo')) {
                throw ValidationException::withMessages(['target' => 'Field evidence cannot promote demo data.']);
            }
            $old = $this->snapshot($target);
            $this->apply($claim, $target);
            $target->save();
            $after = $this->snapshot($target->refresh());
            $missionClaim = $claim->observations->first()?->missionClaim;
            $missionClaim?->forceFill([
                'current_status' => FieldClaimComparison::CONFLICTS_WITH_EXISTING === $claim->comparison
                    ? 'CONFLICT'
                    : 'ACCEPTED',
            ])->save();

            return FieldReviewAction::query()->create([
                'stable_id' => 'field-promotion-'.substr(hash('sha256', $claim->stable_id.'|'.$reviewer->getKey().'|'.microtime(true)), 0, 32),
                'field_mission_id' => $missionClaim?->field_mission_id,
                'field_mission_claim_id' => $missionClaim?->getKey(),
                'field_evidence_claim_id' => $claim->getKey(),
                'actor_user_id' => $reviewer->getKey(),
                'action' => 'PROMOTION_APPLIED',
                'from_status' => $claim->review_status->value,
                'to_status' => 'CANONICAL_FACT_UPDATED',
                'reason' => $reason,
                'before_snapshot' => $old,
                'after_snapshot' => $after,
            ]);
        });
    }

    public function rollback(FieldReviewAction $action, User $reviewer, string $reason): FieldReviewAction
    {
        return DB::transaction(function () use ($action, $reviewer, $reason): FieldReviewAction {
            $action = FieldReviewAction::query()->lockForUpdate()->findOrFail($action->getKey());
            if ($action->action !== 'PROMOTION_APPLIED' || ! is_array($action->before_snapshot)) {
                throw ValidationException::withMessages(['action' => 'Only a recorded promotion with a restorable snapshot can be rolled back.']);
            }
            $target = $this->targetFromSnapshot($action->before_snapshot, true);
            if (! $target instanceof Model) {
                throw ValidationException::withMessages(['target' => 'The promoted target no longer exists.']);
            }
            foreach (($action->before_snapshot['attributes'] ?? []) as $attribute => $value) {
                if ($attribute !== 'id' && $attribute !== 'created_at' && $attribute !== 'updated_at') {
                    $target->setAttribute($attribute, $value);
                }
            }
            $target->save();

            return FieldReviewAction::query()->create([
                'stable_id' => 'field-rollback-'.substr(hash('sha256', $action->stable_id.'|'.$reviewer->getKey().'|'.microtime(true)), 0, 32),
                'field_mission_id' => $action->field_mission_id,
                'field_mission_claim_id' => $action->field_mission_claim_id,
                'field_evidence_claim_id' => $action->field_evidence_claim_id,
                'actor_user_id' => $reviewer->getKey(),
                'action' => 'PROMOTION_ROLLED_BACK',
                'from_status' => 'CANONICAL_FACT_UPDATED',
                'to_status' => 'RESTORED',
                'reason' => $reason,
                'before_snapshot' => $action->after_snapshot,
                'after_snapshot' => $this->snapshot($target->refresh()),
            ]);
        });
    }

    private function loadClaim(FieldEvidenceClaim $claim, bool $lock = false): FieldEvidenceClaim
    {
        $query = FieldEvidenceClaim::query()->with(['observations.surveySession', 'observations.missionClaim']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($claim->getKey());
    }

    private function target(FieldEvidenceClaim $claim, bool $lock = false): ?Model
    {
        $type = $claim->target_type instanceof FieldClaimTargetType ? $claim->target_type : FieldClaimTargetType::from((string) $claim->target_type);
        $class = match ($type) {
            FieldClaimTargetType::PLACE => \App\Models\Place::class,
            FieldClaimTargetType::STOP => Stop::class,
            FieldClaimTargetType::ROUTE => Route::class,
            FieldClaimTargetType::ROUTE_PATTERN => RoutePattern::class,
            FieldClaimTargetType::PATTERN_STOP => RoutePatternStop::class,
            FieldClaimTargetType::TRANSFER => TransferRelationship::class,
            FieldClaimTargetType::FARE => \App\Models\Fare::class,
        };
        $query = $class::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->whereKey($claim->target_id)->first();
    }

    private function targetFromSnapshot(array $snapshot, bool $lock = false): ?Model
    {
        $class = match ($snapshot['target_type'] ?? null) {
            'place' => \App\Models\Place::class,
            'stop' => Stop::class,
            'route' => Route::class,
            'route_pattern' => RoutePattern::class,
            'route_pattern_stop' => RoutePatternStop::class,
            'transfer_relationship' => TransferRelationship::class,
            'fare' => \App\Models\Fare::class,
            default => null,
        };
        if ($class === null) {
            return null;
        }
        $query = $class::query();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->whereKey($snapshot['id'] ?? 0)->first();
    }

    /** @return array<string, mixed> */
    private function snapshot(Model $target): array
    {
        $attributes = $target->getAttributes();
        unset($attributes['geometry'], $attributes['location']);

        return [
            'target_type' => match (true) {
                $target instanceof Stop => 'stop',
                $target instanceof Route => 'route',
                $target instanceof RoutePattern => 'route_pattern',
                $target instanceof RoutePatternStop => 'route_pattern_stop',
                $target instanceof TransferRelationship => 'transfer_relationship',
                default => class_basename($target),
            },
            'id' => $target->getKey(),
            'stable_id' => $target->getAttribute('stable_id'),
            'attributes' => $attributes,
        ];
    }

    /** @return array<string, mixed> */
    private function proposed(FieldEvidenceClaim $claim, ?Model $target): array
    {
        if (! $target instanceof Model) {
            return ['status' => 'TARGET_NOT_FOUND'];
        }
        $type = $claim->claim_type instanceof EvidenceClaimType ? $claim->claim_type : EvidenceClaimType::from((string) $claim->claim_type);
        $source = $claim->provenance_source_id;
        if ($target instanceof RoutePatternStop) {
            $field = match ($type) {
                EvidenceClaimType::PATTERN_STOP_SERVICE => 'service_verification_state',
                EvidenceClaimType::PATTERN_STOP_SEQUENCE => 'sequence_verification_state',
                EvidenceClaimType::PATTERN_STOP_BOARDING => 'boarding_verification_state',
                EvidenceClaimType::PATTERN_STOP_ALIGHTING => 'alighting_verification_state',
                default => null,
            };

            $provenanceField = match ($field) {
                'service_verification_state' => 'service_provenance_source_id',
                'sequence_verification_state' => 'sequence_provenance_source_id',
                'boarding_verification_state' => 'boarding_provenance_source_id',
                'alighting_verification_state' => 'alighting_provenance_source_id',
                default => null,
            };

            return $field === null || $provenanceField === null ? ['status' => 'NO_SAFE_SCOPED_MUTATION'] : [$field => 'VERIFIED', $provenanceField => $source];
        }
        if ($target instanceof Stop && in_array($type, [EvidenceClaimType::BOARDING, EvidenceClaimType::STOP_LOCATION], true)) {
            $precision = $claim->observations
                ->map(fn ($observation): ?string => match ($observation->location_precision?->value ?? $observation->location_precision) {
                    'POINT_ESTIMATE' => 'EXACT_BOARDING_POINT',
                    'BOARDING_AREA' => 'BOARDING_AREA',
                    'GENERAL_AREA' => 'APPROXIMATE_LOCATION',
                    default => null,
                })
                ->filter()
                ->sortBy(fn (string $value): int => match ($value) {
                    'EXACT_BOARDING_POINT' => 0,
                    'BOARDING_AREA' => 1,
                    'APPROXIMATE_LOCATION' => 2,
                    default => 3,
                })
                ->first();
            if ($precision === null) {
                return ['status' => 'NO_SAFE_SCOPED_MUTATION'];
            }

            return ['boarding_precision' => $precision, 'boarding_verification_state' => 'VERIFIED', 'boarding_provenance_source_id' => $source];
        }
        if ($target instanceof RoutePattern && $type === EvidenceClaimType::ROUTE_PATTERN) {
            return ['verification_state' => 'VERIFIED', 'provenance_source_id' => $source];
        }
        if ($target instanceof TransferRelationship && $type === EvidenceClaimType::TRANSFER) {
            return ['verification_state' => 'VERIFIED', 'review_status' => 'SUPPORTED', 'provenance_source_id' => $source];
        }

        return ['status' => 'NO_SAFE_SCOPED_MUTATION'];
    }

    private function apply(FieldEvidenceClaim $claim, Model $target): void
    {
        $proposed = $this->proposed($claim, $target);
        if (($proposed['status'] ?? null) === 'NO_SAFE_SCOPED_MUTATION') {
            throw ValidationException::withMessages(['claim' => 'This claim scope has no safe Batch 020 canonical mutation.']);
        }
        foreach ($proposed as $attribute => $value) {
            $target->setAttribute($attribute, $value);
        }
    }
}
