<?php

namespace App\Services\Evidence;

use App\Enums\EvidenceClaimType;
use App\Enums\EvidenceFreshnessState;
use App\Enums\JourneyTrustState;
use App\Enums\VerificationState;
use App\Models\Fare;
use App\Models\Place;
use App\Models\PlaceAlias;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\TransferRelationship;
use App\Services\Routing\JourneyPlanner;
use App\Support\Trust;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EvidenceCoverageReport
{
    public function __construct(
        private readonly EvidenceFreshnessPolicy $freshnessPolicy,
        private readonly CuratedJourneySet $curatedJourneySet,
        private readonly JourneyPlanner $journeyPlanner,
        private readonly EvidenceBottleneckAnalyzer $bottleneckAnalyzer,
    ) {}

    /** @return array<string, mixed> */
    public function report(
        bool $includeJourneys = true,
        bool $includeDetails = true,
        ?EvidenceJourneyPlanCache $planCache = null,
    ): array {
        $places = Place::query()->with('provenanceSource')->where('is_demo', false)->orderBy('id')->get();
        $aliases = PlaceAlias::query()->with(['provenanceSource', 'place'])->whereHas('place', fn ($query) => $query->where('is_demo', false))->orderBy('id')->get();
        $routes = Route::query()->with('provenanceSource')->where('is_demo', false)->orderBy('id')->get();
        $patterns = RoutePattern::query()->with('provenanceSource')->where('is_demo', false)->orderBy('id')->get();
        $patternStops = RoutePatternStop::query()
            ->with([
                'provenanceSource',
                'serviceProvenanceSource',
                'sequenceProvenanceSource',
                'boardingProvenanceSource',
                'alightingProvenanceSource',
            ])
            ->whereHas('routePattern', fn ($query) => $query->where('is_demo', false))
            ->orderBy('id')
            ->get();
        $stops = Stop::query()->with(['provenanceSource', 'boardingProvenanceSource'])->where('is_demo', false)->orderBy('id')->get();
        $transfers = TransferRelationship::query()->with('provenanceSource')->where('is_demo', false)->orderBy('id')->get();
        $fares = Fare::query()->with('provenanceSource')->where('is_demo', false)->orderBy('id')->get();
        $sources = ProvenanceSource::query()->orderBy('id')->get();

        $report = [
            'places_total' => $places->count(),
            'places_verified' => $places->filter(fn (Place $place): bool => Trust::effectiveState($place) === VerificationState::VERIFIED->value)->count(),
            'aliases_total' => $aliases->count(),
            'routes_total' => $routes->count(),
            'routes_verified' => $routes->filter(fn (Route $route): bool => $this->verifiedModel($route, EvidenceClaimType::ROUTE_IDENTITY))->count(),
            'patterns_total' => $patterns->count(),
            'patterns_verified' => $patterns->filter(fn (RoutePattern $pattern): bool => $this->verifiedModel($pattern, EvidenceClaimType::ROUTE_PATTERN))->count(),
            'stops_total' => $stops->count(),
            'stops_verified' => $stops->filter(fn (Stop $stop): bool => Trust::effectiveState($stop) === VerificationState::VERIFIED->value)->count(),
            'pattern_stops_total' => $patternStops->count(),
            'pattern_stops_service_verified' => $this->verifiedFactCount($patternStops, 'service'),
            'pattern_stops_sequence_verified' => $this->verifiedFactCount($patternStops, 'sequence'),
            'pattern_stops_boarding_verified' => $this->verifiedFactCount($patternStops, 'boarding'),
            'pattern_stops_alighting_verified' => $this->verifiedFactCount($patternStops, 'alighting'),
            'boarding_points_total' => $stops->where('is_boarding_point', true)->count(),
            'boarding_points_exact' => $stops->where('is_boarding_point', true)->where('boarding_precision', 'EXACT_BOARDING_POINT')->count(),
            'boarding_points_area' => $stops->where('is_boarding_point', true)->where('boarding_precision', 'BOARDING_AREA')->count(),
            'boarding_points_approximate' => $stops->where('is_boarding_point', true)->where('boarding_precision', 'APPROXIMATE_LOCATION')->count(),
            'boarding_points_unknown' => $stops->where('is_boarding_point', true)->where('boarding_precision', 'UNKNOWN')->count(),
            'transfers_total' => $transfers->count(),
            'transfers_verified' => $transfers->filter(fn (TransferRelationship $transfer): bool => $this->verifiedTransfer($transfer))->count(),
            'transfers_unverified' => $transfers->filter(fn (TransferRelationship $transfer): bool => $transfer->review_status?->value !== 'REJECTED' && ! $this->verifiedTransfer($transfer))->count(),
            'transfers_rejected' => $transfers->filter(fn (TransferRelationship $transfer): bool => $transfer->review_status?->value === 'REJECTED')->count(),
            'fare_rules_current' => $fares->filter(fn (Fare $fare): bool => $this->currentFare($fare))->count(),
            'fare_rules_stale' => $fares->filter(fn (Fare $fare): bool => $this->staleFare($fare))->count(),
            'fare_rules_retired' => $fares->filter(fn (Fare $fare): bool => ($fare->verification_state?->value ?? $fare->verification_state) === VerificationState::RETIRED->value)->count(),
            'fare_rules_unknown_freshness' => $fares->filter(fn (Fare $fare): bool => $this->fareFreshness($fare) === EvidenceFreshnessState::UNKNOWN_FRESHNESS)->count(),
            'fare_rules_unverified' => $fares->filter(fn (Fare $fare): bool => ($fare->verification_state?->value ?? $fare->verification_state) !== VerificationState::VERIFIED->value)->count(),
            'fare_rules_missing_scope' => $fares->filter(fn (Fare $fare): bool => ($fare->verification_state?->value ?? $fare->verification_state) !== VerificationState::RETIRED->value
                && ! in_array($this->fareClaimScope($fare), $fare->provenanceSource?->evidence_scope ?? [], true))->count(),
            'fare_rules_current_unverified' => $fares->filter(fn (Fare $fare): bool => $this->currentFare($fare)
                && ($fare->verification_state?->value ?? $fare->verification_state) !== VerificationState::VERIFIED->value)->count(),
            'provenance_sources_total' => $sources->count(),
            'freshness' => $this->freshnessSummary($sources),
            'source_quality' => $this->sourceQualitySummary($sources),
            'curated_journeys_total' => 0,
            'curated_journeys_verified' => 0,
            'curated_journeys_mixed' => 0,
            'curated_journeys_unverified' => 0,
            'verified_journey_percent' => 0.0,
            'curated_journeys' => [],
        ];

        if ($includeDetails) {
            $report['fact_audit'] = $this->factAudit($places, $aliases, $routes, $patterns, $patternStops, $stops, $transfers, $fares);
            $report['provenance_audit'] = $sources->map(fn (ProvenanceSource $source): array => $this->sourceAudit($source))->all();
        }

        if ($includeJourneys) {
            $curated = $this->curatedJourneys($planCache);
            $report['curated_journeys'] = $curated;
            $report['curated_journeys_total'] = count($curated);
            foreach ($curated as $scenario) {
                match ($scenario['trust'] ?? JourneyTrustState::UNVERIFIED->value) {
                    JourneyTrustState::VERIFIED->value => $report['curated_journeys_verified']++,
                    JourneyTrustState::MIXED->value => $report['curated_journeys_mixed']++,
                    default => $report['curated_journeys_unverified']++,
                };
            }
            $report['verified_journey_percent'] = $report['curated_journeys_total'] === 0
                ? 0.0
                : round(($report['curated_journeys_verified'] / $report['curated_journeys_total']) * 100, 2);
        }

        return $report;
    }

    /** @return list<array<string, mixed>> */
    private function curatedJourneys(?EvidenceJourneyPlanCache $planCache = null): array
    {
        $scenarios = $this->curatedJourneySet->scenarios();
        if ($scenarios === []) {
            return [];
        }
        $slugs = collect($scenarios)
            ->flatMap(fn (array $scenario): array => [$scenario['origin_place_slug'], $scenario['destination_place_slug']])
            ->unique()
            ->values();
        $places = Place::query()
            ->with(['aliases', 'provenanceSource'])
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
            ->when(config('database.default') === 'pgsql', fn ($builder) => $builder->addSelect([
                'places.*',
                DB::raw('ST_AsText(places.location) AS location_wkt'),
            ]))
            ->get()
            ->keyBy('slug');
        $results = [];
        foreach ($scenarios as $scenario) {
            $origin = $places->get($scenario['origin_place_slug']);
            $destination = $places->get($scenario['destination_place_slug']);
            if (! $origin instanceof Place || ! $destination instanceof Place) {
                $results[] = [
                    ...$scenario,
                    'trust' => JourneyTrustState::UNVERIFIED->value,
                    'journey_id' => null,
                    'status' => 'PLACE_NOT_FOUND',
                    'bottlenecks' => [[
                        'type' => 'PLACE',
                        'reason' => 'Curated place reference does not resolve in production data.',
                    ]],
                ];

                continue;
            }
            try {
                $search = $planCache?->plan($origin, $destination)
                    ?? $this->journeyPlanner->plan($origin, $destination);
                $journey = $search->journeys[0] ?? null;
            } catch (\Throwable $exception) {
                $journey = null;
                $search = null;
            }
            $results[] = [
                ...$scenario,
                'trust' => $journey?->trustState->value ?? JourneyTrustState::UNVERIFIED->value,
                'journey_id' => $journey?->id,
                'route_codes' => $journey === null ? [] : collect($journey->legs)
                    ->filter(fn ($leg): bool => $leg->route !== null)
                    ->map(fn ($leg): string => (string) ($leg->route['code'] ?? ''))
                    ->filter()
                    ->values()
                    ->all(),
                'status' => $journey === null ? ($search?->noRouteCode ?? 'PLANNER_ERROR') : 'ROUTABLE',
                'bottlenecks' => $journey === null ? [[
                    'type' => 'JOURNEY',
                    'reason' => 'Biyahe does not currently know a supported journey for this scenario.',
                ]] : $this->bottleneckAnalyzer->analyze($journey),
            ];
        }

        return $results;
    }

    /** @param iterable<RoutePatternStop> $associations */
    private function verifiedFactCount(iterable $associations, string $fact): int
    {
        $count = 0;
        foreach ($associations as $association) {
            if (Trust::effectiveFactState($association, $fact) === VerificationState::VERIFIED->value) {
                $count++;
            }
        }

        return $count;
    }

    private function verifiedModel(Route|RoutePattern $model, EvidenceClaimType $claim): bool
    {
        return Trust::effectiveState($model) === VerificationState::VERIFIED->value;
    }

    private function verifiedTransfer(TransferRelationship $transfer): bool
    {
        $review = $transfer->review_status?->value ?? $transfer->review_status;

        return Trust::effectiveState($transfer) === VerificationState::VERIFIED->value
            && $review === 'SUPPORTED';
    }

    private function currentFare(Fare $fare): bool
    {
        $state = $fare->verification_state?->value ?? $fare->verification_state;
        $until = $fare->effective_until;
        $source = $fare->provenanceSource;
        $scope = $this->fareClaimScope($fare);

        return ! in_array($state, [VerificationState::STALE->value, VerificationState::RETIRED->value], true)
            && ($until === null || $until->greaterThanOrEqualTo(Carbon::today()))
            && in_array($this->fareFreshness($fare), [EvidenceFreshnessState::CURRENT, EvidenceFreshnessState::AGING], true)
            && in_array($scope, $source?->evidence_scope ?? [], true)
            && $source?->conflict_status?->value !== 'OPEN'
            && $source?->superseded_by_id === null;
    }

    private function staleFare(Fare $fare): bool
    {
        $state = $fare->verification_state?->value ?? $fare->verification_state;
        $until = $fare->effective_until;

        if ($state === VerificationState::RETIRED->value) {
            return false;
        }

        return $state === VerificationState::STALE->value
            || ($until !== null && $until->lessThan(Carbon::today()))
            || $this->fareFreshness($fare) === EvidenceFreshnessState::STALE;
    }

    private function fareFreshness(Fare $fare): EvidenceFreshnessState
    {
        return $this->freshnessPolicy->classify($fare->provenanceSource, $this->fareClaimScope($fare));
    }

    private function fareClaimScope(Fare $fare): string
    {
        return $fare->applicability_scope === 'VEHICLE_CLASS'
            ? EvidenceClaimType::FARE_GENERAL_TRADITIONAL_PUJ->value
            : EvidenceClaimType::FARE->value;
    }

    /** @param iterable<ProvenanceSource> $sources @return array<string, array<string, int>> */
    private function freshnessSummary(iterable $sources): array
    {
        $summary = [];
        foreach ($sources as $source) {
            $scopes = $source->evidence_scope ?? [];
            if ($scopes === []) {
                $scopes = ['UNSCOPED'];
            }
            foreach ($scopes as $scope) {
                if (! is_string($scope)) {
                    continue;
                }
                $state = $this->freshnessPolicy->classify($source, $scope)->value;
                $summary[$scope][$state] = ($summary[$scope][$state] ?? 0) + 1;
            }
        }
        ksort($summary);

        return $summary;
    }

    /** @param iterable<ProvenanceSource> $sources @return array<string, int> */
    private function sourceQualitySummary(iterable $sources): array
    {
        $summary = [];
        foreach ($sources as $source) {
            $label = $this->freshnessPolicy->sourceQuality($source)['label'];
            $summary[$label] = ($summary[$label] ?? 0) + 1;
        }

        ksort($summary);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function factAudit($places, $aliases, $routes, $patterns, $patternStops, $stops, $transfers, $fares): array
    {
        return [
            'places' => $places->map(fn (Place $place): array => $this->modelAudit($place, EvidenceClaimType::PLACE_LOCATION->value))->all(),
            'aliases' => $aliases->map(fn (PlaceAlias $alias): array => [
                'id' => $alias->getKey(),
                'stable_id' => $alias->stable_id,
                'alias' => $alias->alias,
                'place_id' => $alias->place_id,
                'place_name' => $alias->place?->name,
                'active' => (bool) $alias->is_active,
                'provenance_source_id' => $alias->provenance_source_id,
                'source' => $this->sourceAudit($alias->provenanceSource),
            ])->all(),
            'routes' => $routes->map(fn (Route $route): array => $this->modelAudit($route, EvidenceClaimType::ROUTE_IDENTITY->value))->all(),
            'patterns' => $patterns->map(fn (RoutePattern $pattern): array => $this->modelAudit($pattern, EvidenceClaimType::ROUTE_PATTERN->value))->all(),
            'pattern_stops' => $patternStops->map(function (RoutePatternStop $association): array {
                $facts = [];
                foreach (['service', 'sequence', 'boarding', 'alighting'] as $fact) {
                    $facts[$fact] = $this->factAuditEntry(
                        'route_pattern_stop',
                        $association->getKey(),
                        $association->stable_id,
                        Trust::evidenceState($association, $fact),
                        Trust::effectiveFactState($association, $fact),
                        $association->{$fact.'ProvenanceSource'},
                        'PATTERN_STOP_'.strtoupper($fact),
                    );
                }

                return [
                    'id' => $association->getKey(),
                    'stable_id' => $association->stable_id,
                    'pattern_id' => $association->route_pattern_id,
                    'stop_id' => $association->stop_id,
                    'sequence' => $association->sequence,
                    'facts' => $facts,
                ];
            })->all(),
            'stops' => $stops->map(function (Stop $stop): array {
                return [
                    'id' => $stop->getKey(),
                    'stable_id' => $stop->stable_id,
                    'location' => $this->factAuditEntry('stop', $stop->getKey(), $stop->stable_id, $stop->verification_state?->value ?? $stop->verification_state, Trust::effectiveState($stop), $stop->provenanceSource, 'STOP_LOCATION'),
                    'boarding' => $this->factAuditEntry('stop', $stop->getKey(), $stop->stable_id, $stop->boarding_verification_state?->value ?? $stop->boarding_verification_state, Trust::effectiveFactState($stop, 'boarding'), $stop->boardingProvenanceSource, 'BOARDING'),
                    'boarding_precision' => $stop->boarding_precision?->value ?? $stop->boarding_precision,
                    'is_boarding_point' => (bool) $stop->is_boarding_point,
                ];
            })->all(),
            'transfers' => $transfers->map(fn (TransferRelationship $transfer): array => $this->modelAudit(
                $transfer,
                EvidenceClaimType::TRANSFER->value,
                ['from_stop_id' => $transfer->from_stop_id, 'to_stop_id' => $transfer->to_stop_id, 'review_status' => $transfer->review_status?->value ?? $transfer->review_status],
            ))->all(),
            'fares' => $fares->map(fn (Fare $fare): array => $this->modelAudit(
                $fare,
                $fare->applicability_scope === 'VEHICLE_CLASS' ? EvidenceClaimType::FARE_GENERAL_TRADITIONAL_PUJ->value : EvidenceClaimType::FARE->value,
                ['applicability_scope' => $fare->applicability_scope, 'amount' => $fare->amount, 'currency' => $fare->currency, 'effective_from' => $fare->effective_from?->toDateString(), 'effective_until' => $fare->effective_until?->toDateString()],
            ))->all(),
        ];
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function modelAudit(Place|Route|RoutePattern|TransferRelationship|Fare $model, string $claim, array $extra = []): array
    {
        return [
            'id' => $model->getKey(),
            'stable_id' => $model->stable_id,
            'claim' => $claim,
            'raw_state' => $model->verification_state?->value ?? $model->verification_state,
            'effective_state' => Trust::effectiveState($model),
            'evidence' => $this->factAuditEntry(
                $model->getTable(),
                $model->getKey(),
                $model->stable_id,
                $model->verification_state?->value ?? $model->verification_state,
                Trust::effectiveState($model),
                $model->provenanceSource,
                $claim,
            ),
            ...$extra,
        ];
    }

    /** @return array<string, mixed> */
    private function factAuditEntry(string $recordType, int $recordId, ?string $stableId, mixed $rawState, mixed $effectiveState, ?ProvenanceSource $source, string $claim): array
    {
        return [
            'record_type' => $recordType,
            'record_id' => $recordId,
            'stable_id' => $stableId,
            'claim' => $claim,
            'raw_state' => $rawState instanceof VerificationState ? $rawState->value : $rawState,
            'effective_state' => $effectiveState,
            'provenance_source_id' => $source?->getKey(),
            'provenance_source_stable_id' => $source?->stable_id,
            'freshness' => $this->freshnessPolicy->classify($source, $claim)->value,
            'source_quality' => $this->freshnessPolicy->sourceQuality($source),
            'conflict_status' => $source?->conflict_status?->value ?? $source?->conflict_status,
            'conflict_group' => $source?->conflict_group,
            'superseded_by_id' => $source?->superseded_by_id,
            'evidence_scope' => $source?->evidence_scope,
            'checked_at' => $source?->checked_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function sourceAudit(?ProvenanceSource $source): ?array
    {
        if (! $source instanceof ProvenanceSource) {
            return null;
        }

        return [
            'id' => $source->getKey(),
            'stable_id' => $source->stable_id,
            'source_type' => $source->source_type,
            'publisher' => $source->publisher,
            'title' => $source->title,
            'url' => $source->url,
            'published_at' => $source->published_at?->toDateString(),
            'observed_at' => $source->observed_at?->toDateString(),
            'effective_from' => $source->effective_from?->toDateString(),
            'effective_until' => $source->effective_until?->toDateString(),
            'checked_at' => $source->checked_at?->toDateString(),
            'evidence_scope' => $source->evidence_scope,
            'conflict_status' => $source->conflict_status?->value ?? $source->conflict_status,
            'conflict_group' => $source->conflict_group,
            'superseded_by_id' => $source->superseded_by_id,
            'freshness_by_scope' => collect($source->evidence_scope ?? [])
                ->mapWithKeys(fn (string $scope): array => [$scope => $this->freshnessPolicy->classify($source, $scope)->value])
                ->all(),
            'verification_method' => $source->verification_method,
            'verified_by' => $source->verified_by,
            'notes' => $source->notes,
        ];
    }
}
