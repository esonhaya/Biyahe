<?php

namespace App\Support;

use App\Enums\VerificationState;
use App\Services\Evidence\EvidenceFreshnessPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class Trust
{
    public static function apply(Builder $query, Request $request, string $table): Builder
    {
        if (! $request->boolean('include_demo')) {
            $query->where("{$table}.is_demo", false);
        }

        if (! $request->boolean('include_retired')) {
            $query->where("{$table}.verification_state", '!=', VerificationState::RETIRED->value);
        }

        if ($request->boolean('trusted_only')) {
            $query->where("{$table}.is_demo", false)
                ->where("{$table}.verification_state", VerificationState::VERIFIED->value);
            if ($table === 'fares') {
                $query->where(function (Builder $builder): void {
                    $builder->where(function (Builder $vehicleClass): void {
                        $vehicleClass->where('fares.applicability_scope', 'VEHICLE_CLASS');
                        self::whereTrustedProvenance($vehicleClass, 'FARE_GENERAL_TRADITIONAL_PUJ');
                    })->orWhere(function (Builder $routeFare): void {
                        $routeFare->where('fares.applicability_scope', '!=', 'VEHICLE_CLASS');
                        self::whereTrustedProvenance($routeFare, 'FARE');
                    });
                });
            } elseif (($scope = self::claimScopeForTable($table)) !== null) {
                self::whereTrustedProvenance($query, $scope);
            }
            if ($table === 'transfer_relationships') {
                $query->where('review_status', 'SUPPORTED');
            }
        } elseif ($request->filled('verification_state')) {
            $state = strtoupper((string) $request->query('verification_state'));
            if (in_array($state, VerificationState::values(), true)) {
                $query->where("{$table}.verification_state", $state);
            }
        }

        return $query;
    }

    /**
     * @return array{state: string|null, is_demo: bool, trusted: bool}
     */
    public static function status(Model $model): array
    {
        $state = self::rawState($model);
        $isDemo = (bool) $model->is_demo;

        return [
            'state' => self::effectiveState($model),
            'is_demo' => $isDemo,
            'trusted' => self::effectiveState($model) === VerificationState::VERIFIED->value && ! $isDemo,
            'evidence' => self::evidenceSummary($model),
        ];
    }

    public static function isRoutable(Model $model): bool
    {
        if ($model->getTable() === 'route_pattern_stops') {
            return self::isFactRoutable($model, ['service', 'sequence', 'boarding', 'alighting']);
        }

        $state = self::effectiveState($model);

        if ($model->getTable() === 'transfer_relationships') {
            $reviewStatus = $model->review_status?->value ?? $model->review_status;
            if (in_array($reviewStatus, ['REJECTED', 'NEEDS_RECHECK'], true)) {
                return false;
            }
        }

        return (bool) ($model->is_active ?? true)
            && ! (bool) ($model->is_demo ?? false)
            && in_array($state, [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value], true)
            && ! in_array(VerificationState::DRAFT->value, self::evidenceStates($model), true)
            && ! in_array(VerificationState::STALE->value, self::evidenceStates($model), true)
            && ! in_array(VerificationState::RETIRED->value, self::evidenceStates($model), true);
    }

    /** @param list<string> $facts */
    public static function isFactRoutable(Model $model, array $facts): bool
    {
        if ($model->getTable() !== 'route_pattern_stops'
            || ! (bool) ($model->is_active ?? true)
            || (bool) ($model->is_demo ?? false)
            || ! in_array(self::rawState($model), [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value], true)) {
            return false;
        }

        foreach ($facts as $fact) {
            if (! in_array(self::effectiveFactState($model, $fact), [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value], true)) {
                return false;
            }
        }

        return true;
    }

    public static function applyRoutable(Builder $query, string $table): Builder
    {
        $query = $query
            ->where("{$table}.is_active", true)
            ->where("{$table}.is_demo", false)
            ->whereIn("{$table}.verification_state", [
                VerificationState::VERIFIED->value,
                VerificationState::UNVERIFIED->value,
            ]);

        return $query;
    }

    public static function rawState(Model $model): ?string
    {
        $state = $model->verification_state ?? null;

        return $state instanceof VerificationState ? $state->value : $state;
    }

    /** @return list<string> */
    public static function evidenceStates(Model $model): array
    {
        $states = [];
        foreach ([
            'service_verification_state',
            'sequence_verification_state',
            'boarding_verification_state',
            'alighting_verification_state',
        ] as $attribute) {
            $state = $model->{$attribute} ?? null;
            if ($state instanceof VerificationState) {
                $state = $state->value;
            }
            if (is_string($state) && $state !== '') {
                $states[] = $state;
            }
        }

        return array_values(array_unique($states));
    }

    public static function effectiveState(Model $model): ?string
    {
        $baseState = self::rawState($model);
        if ($baseState === null) {
            return null;
        }

        if ($model->getTable() === 'route_pattern_stops') {
            $factStates = [];
            foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
                $factStates[] = self::effectiveFactState($model, $fact);
            }
            if (in_array(VerificationState::RETIRED->value, $factStates, true)) {
                return VerificationState::RETIRED->value;
            }
            if (in_array(VerificationState::STALE->value, $factStates, true)) {
                return VerificationState::STALE->value;
            }
            if (in_array(VerificationState::DRAFT->value, $factStates, true)) {
                return VerificationState::DRAFT->value;
            }
            if (! collect($factStates)->every(static fn (?string $state): bool => $state === VerificationState::VERIFIED->value)) {
                return in_array($baseState, [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value], true)
                    ? VerificationState::UNVERIFIED->value
                    : $baseState;
            }
        }

        $evidenceStates = self::evidenceStates($model);
        if (in_array(VerificationState::RETIRED->value, $evidenceStates, true)) {
            return VerificationState::RETIRED->value;
        }
        if (in_array(VerificationState::STALE->value, $evidenceStates, true)) {
            return VerificationState::STALE->value;
        }
        if ($evidenceStates !== [] && in_array(VerificationState::DRAFT->value, $evidenceStates, true)) {
            return VerificationState::DRAFT->value;
        }

        if ($baseState === VerificationState::VERIFIED->value && self::hasBlockedProvenance($model)) {
            return VerificationState::UNVERIFIED->value;
        }

        if ($baseState === VerificationState::VERIFIED->value) {
            $scope = self::claimScope($model);
            if ($scope !== null && ! self::sourceSupportsRelation($model, 'provenanceSource', $scope)) {
                return VerificationState::UNVERIFIED->value;
            }
            if ($model->getTable() === 'transfer_relationships'
                && ($model->review_status?->value ?? $model->review_status) !== 'SUPPORTED') {
                return VerificationState::UNVERIFIED->value;
            }
        }

        return $baseState;
    }

    public static function evidenceState(Model $model, string $fact): ?string
    {
        $attribute = $fact.'_verification_state';
        $state = $model->{$attribute} ?? null;

        return $state instanceof VerificationState ? $state->value : $state;
    }

    public static function effectiveFactState(Model $model, string $fact): ?string
    {
        $state = self::evidenceState($model, $fact);
        if ($state !== VerificationState::VERIFIED->value) {
            return $state;
        }

        $source = $fact.'ProvenanceSource';
        $scope = $model->getTable() === 'stops'
            ? 'BOARDING'
            : 'PATTERN_STOP_'.strtoupper($fact);

        if ($model->getTable() === 'stops') {
            if (! (bool) $model->is_boarding_point
                || ($model->boarding_precision?->value ?? $model->boarding_precision) === 'UNKNOWN') {
                return VerificationState::UNVERIFIED->value;
            }
        }

        return self::sourceSupportsRelation($model, $source, $scope)
            ? $state
            : VerificationState::UNVERIFIED->value;
    }

    /** @param list<string> $facts */
    public static function effectiveFactSetState(Model $model, array $facts): string
    {
        $states = array_map(fn (string $fact): ?string => self::effectiveFactState($model, $fact), $facts);
        foreach ([VerificationState::RETIRED, VerificationState::STALE, VerificationState::DRAFT] as $blockedState) {
            if (in_array($blockedState->value, $states, true)) {
                return $blockedState->value;
            }
        }

        return $states !== [] && count(array_filter($states, static fn (?string $state): bool => $state === VerificationState::VERIFIED->value)) === count($states)
            ? VerificationState::VERIFIED->value
            : VerificationState::UNVERIFIED->value;
    }

    public static function sourceSupportsRelation(Model $model, string $relation, string $scope): bool
    {
        if (! $model->relationLoaded($relation)) {
            return false;
        }
        $source = $model->getRelation($relation);
        if (! $source instanceof Model || self::sourceBlocked($source)) {
            return false;
        }
        $scopes = $source->evidence_scope;

        return is_array($scopes)
            && in_array($scope, $scopes, true)
            && app(EvidenceFreshnessPolicy::class)->isUsableForVerifiedClaim($source, $scope);
    }

    private static function claimScope(Model $model): ?string
    {
        if ($model->getTable() === 'fares' && $model->applicability_scope === 'VEHICLE_CLASS') {
            return 'FARE_GENERAL_TRADITIONAL_PUJ';
        }

        return self::claimScopeForTable($model->getTable());
    }

    private static function claimScopeForTable(string $table): ?string
    {
        return match ($table) {
            'places' => 'PLACE_LOCATION',
            'stops' => 'STOP_LOCATION',
            'routes', 'operators' => 'ROUTE_IDENTITY',
            'route_patterns' => 'ROUTE_PATTERN',
            'transfer_relationships' => 'TRANSFER',
            'fares' => 'FARE',
            default => null,
        };
    }

    private static function whereTrustedProvenance(Builder $query, string $scope): void
    {
        $freshnessKey = str_starts_with($scope, 'PATTERN_STOP_') ? 'PATTERN_STOP' : $scope;
        $defaultFreshness = (array) config('biyahe.evidence.freshness.default', ['current_days' => 180, 'aging_days' => 365]);
        $scopeFreshness = (array) config('biyahe.evidence.freshness.'.$freshnessKey, $defaultFreshness);
        $freshnessCutoff = now()->subDays((int) ($scopeFreshness['aging_days'] ?? 365))->toDateString();
        $query->whereHas('provenanceSource', function (Builder $source) use ($scope, $freshnessCutoff): void {
            $source->whereJsonContains('evidence_scope', $scope)
                ->whereDate('checked_at', '>=', $freshnessCutoff)
                ->where(function (Builder $conflict): void {
                    $conflict->whereNull('conflict_status')->orWhere('conflict_status', '!=', 'OPEN');
                })
                ->whereNull('superseded_by_id');
        });
    }

    /** @return list<Model> */
    public static function provenanceSources(Model $model): array
    {
        $relations = [
            'provenanceSource',
            'boardingProvenanceSource',
            'serviceProvenanceSource',
            'sequenceProvenanceSource',
            'alightingProvenanceSource',
        ];
        $sources = [];
        foreach ($relations as $relation) {
            if (! $model->relationLoaded($relation)) {
                continue;
            }
            $source = $model->getRelation($relation);
            if ($source instanceof Model) {
                $sources[$source->getKey()] = $source;
            }
        }

        return array_values($sources);
    }

    private static function hasBlockedProvenance(Model $model): bool
    {
        foreach (self::provenanceSources($model) as $source) {
            if (self::sourceBlocked($source)) {
                return true;
            }
        }

        return false;
    }

    private static function sourceBlocked(Model $source): bool
    {
        $conflict = $source->conflict_status?->value ?? $source->conflict_status;

        return $conflict === 'OPEN' || $source->superseded_by_id !== null;
    }

    /** @return array<string, mixed> */
    private static function evidenceSummary(Model $model): array
    {
        $summary = [];
        foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
            $state = self::evidenceState($model, $fact);
            if ($state !== null) {
                $summary[$fact] = ['state' => $state];
            }
        }
        if ($model->getTable() === 'stops') {
            $boardingState = self::evidenceState($model, 'boarding');
            if ($boardingState === null) {
                return $summary;
            }
            $summary['boarding'] = [
                'state' => self::effectiveFactState($model, 'boarding'),
                'precision' => $model->boarding_precision?->value ?? $model->boarding_precision,
            ];
        }

        return $summary;
    }
}
