<?php

namespace App\Services\Routing;

use App\Enums\JourneyLegType;
use App\Enums\VerificationState;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A deliberately bounded release audit for the supported Cebu slice. It is
 * read-only and keeps the representative matrix explicit so it cannot grow
 * into an accidental all-pairs production benchmark.
 */
final class NetworkReleaseAudit
{
    /** @var array<string, array{label: string, slugs: list<string>}> */
    private const CORRIDORS = [
        'COLON_CARBON' => ['label' => 'Colon / Carbon', 'slugs' => ['colon', 'carbon-market-unit-3']],
        'FUENTE_JONES' => ['label' => 'Fuente / Jones', 'slugs' => ['fuente-osmena-circle', 'jones-avenue']],
        'CAPITOL_ESCARIO' => ['label' => 'Capitol / Escario', 'slugs' => ['cebu-provincial-capitol', 'sacred-heart']],
        'AYALA_BUSINESS_PARK' => ['label' => 'Ayala / Cebu Business Park', 'slugs' => ['ayala-center-cebu', 'cebu-business-park']],
        'IT_PARK_LAHUG' => ['label' => 'IT Park / Lahug', 'slugs' => ['cebu-it-park', 'lahug-market']],
        'MABOLO' => ['label' => 'Mabolo', 'slugs' => ['mabolo']],
        'SM_NORTH_RECLAMATION' => ['label' => 'SM City / North Reclamation', 'slugs' => ['sm-city-cebu', 'north-reclamation-area']],
        'BANILAD' => ['label' => 'Banilad', 'slugs' => ['banilad-town-center', 'gaisano-country-mall']],
        'TALAMBAN' => ['label' => 'Talamban', 'slugs' => ['talamban']],
        'GUADALUPE' => ['label' => 'Guadalupe', 'slugs' => ['guadalupe']],
        'LABANGON' => ['label' => 'Labangon', 'slugs' => ['labangon']],
        'PARDO_SOUTH' => ['label' => 'Pardo / south corridor', 'slugs' => ['punta-princesa', 'cebu-south-bus-terminal']],
    ];

    /** @var list<array{origin: string, destination: string}> */
    private const MATRIX = [
        ['origin' => 'colon', 'destination' => 'ayala-center-cebu'],
        ['origin' => 'ayala-center-cebu', 'destination' => 'cebu-it-park'],
        ['origin' => 'fuente-osmena-circle', 'destination' => 'talamban'],
        ['origin' => 'cebu-it-park', 'destination' => 'sm-city-cebu'],
        ['origin' => 'mabolo', 'destination' => 'colon'],
        ['origin' => 'banilad-town-center', 'destination' => 'colon'],
        ['origin' => 'guadalupe', 'destination' => 'ayala-center-cebu'],
        ['origin' => 'labangon', 'destination' => 'cebu-it-park'],
        ['origin' => 'talamban', 'destination' => 'ayala-center-cebu'],
        ['origin' => 'sm-city-cebu', 'destination' => 'cebu-provincial-capitol'],
        ['origin' => 'cebu-provincial-capitol', 'destination' => 'carbon-market-unit-3'],
        ['origin' => 'carbon-market-unit-3', 'destination' => 'labangon'],
        ['origin' => 'colon', 'destination' => 'cebu-exchange'],
        ['origin' => 'cebu-exchange', 'destination' => 'cebu-it-park'],
        ['origin' => 'fuente-osmena-circle', 'destination' => 'gaisano-grand-mall-talamban'],
        ['origin' => 'cebu-it-park', 'destination' => 'apm-mall'],
        ['origin' => 'mabolo', 'destination' => 'cebu-metropolitan-cathedral'],
        ['origin' => 'banilad-town-center', 'destination' => 'cebu-doctors-hospital'],
        ['origin' => 'guadalupe', 'destination' => 'cebu-exchange'],
        ['origin' => 'labangon', 'destination' => 'banawa'],
        ['origin' => 'talamban', 'destination' => 'cebu-doctors-hospital'],
        ['origin' => 'sm-city-cebu', 'destination' => 'pardo'],
        ['origin' => 'cebu-provincial-capitol', 'destination' => 'colonnade-mall'],
        ['origin' => 'carbon-market-unit-3', 'destination' => 'inayawan-public-market'],
        ['origin' => 'bulacao', 'destination' => 'ayala-center-cebu'],
        ['origin' => 'pardo', 'destination' => 'sm-city-cebu'],
        ['origin' => 'banawa', 'destination' => 'talamban'],
        ['origin' => 'apm-mall', 'destination' => 'colon'],
        ['origin' => 'cebu-doctors-hospital', 'destination' => 'cebu-exchange'],
        ['origin' => 'jy-square-mall', 'destination' => 'cebu-south-bus-terminal'],
        ['origin' => 'gaisano-grand-mall-talamban', 'destination' => 'guadalupe'],
        ['origin' => 'inayawan-public-market', 'destination' => 'cebu-exchange'],
        ['origin' => 'colonnade-mall', 'destination' => 'gaisano-grand-mall-talamban'],
        ['origin' => 'cebu-metropolitan-cathedral', 'destination' => 'talamban'],
        ['origin' => 'cebu-exchange', 'destination' => 'pardo'],
        ['origin' => 'pardo', 'destination' => 'cebu-it-park'],
        ['origin' => 'bulacao', 'destination' => 'talamban'],
        ['origin' => 'labangon', 'destination' => 'apm-mall'],
        ['origin' => 'cebu-provincial-capitol', 'destination' => 'banawa'],
        ['origin' => 'north-reclamation-area', 'destination' => 'pardo'],
        ['origin' => 'gaisano-capital-south', 'destination' => 'ayala-center-cebu'],
        ['origin' => 'cebu-doctors-hospital', 'destination' => 'carbon-market-unit-3'],
        ['origin' => 'jy-square-mall', 'destination' => 'bulacao'],
        ['origin' => 'apm-mall', 'destination' => 'talamban'],
        ['origin' => 'cebu-it-park', 'destination' => 'bulacao'],
        ['origin' => 'guadalupe', 'destination' => 'gaisano-grand-mall-talamban'],
        ['origin' => 'punta-princesa', 'destination' => 'cebu-exchange'],
    ];

    public function __construct(private readonly JourneyPlanner $planner) {}

    /** @return array<string, mixed> */
    public function audit(?int $maxPairs = null): array
    {
        $startedAt = hrtime(true);
        $maxPairs = min(max($maxPairs ?? count(self::MATRIX), 1), count(self::MATRIX));
        $places = Place::query()
            ->with(['aliases', 'city', 'provenanceSource'])
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
            ->when(config('database.default') === 'pgsql', fn (Builder $builder) => $builder->addSelect([
                'places.*',
                DB::raw('ST_AsText(places.location) AS location_wkt'),
            ]))
            ->orderBy('id')
            ->get()
            ->keyBy('slug');

        $inventory = $this->inventory();
        $corridors = $this->corridors($places);
        $planningContext = new JourneyPlanningContext((int) config('biyahe.routing.max_cached_patterns', 250));
        $rows = [];
        $metrics = [
            'total_pairs' => 0,
            'routable' => 0,
            'direct' => 0,
            'one_transfer' => 0,
            'two_transfer' => 0,
            'unsupported' => 0,
            'verified' => 0,
            'mixed' => 0,
            'unverified' => 0,
            'walk_fallback_used' => 0,
            'disruption_aware' => 0,
            'suspicious_journeys' => 0,
        ];

        foreach (array_slice(self::MATRIX, 0, $maxPairs) as $pair) {
            $origin = $places->get($pair['origin']);
            $destination = $places->get($pair['destination']);
            $metrics['total_pairs']++;
            if (! $origin instanceof Place || ! $destination instanceof Place) {
                $metrics['unsupported']++;
                $rows[] = [
                    'origin' => $pair['origin'],
                    'destination' => $pair['destination'],
                    'status' => 'SEARCH_LOCATION_UNSUPPORTED',
                    'journeys' => 0,
                ];

                continue;
            }

            $result = $this->planner->plan(
                $origin,
                $destination,
                maxTransfers: (int) config('biyahe.routing.default_max_transfers', 2),
                context: $planningContext,
            );
            $journeys = $result->journeys;
            $row = [
                'origin' => $origin->slug,
                'destination' => $destination->slug,
                'status' => $journeys === [] ? ($result->noRouteCode ?? 'NO_KNOWN_JOURNEY') : 'ROUTABLE',
                'journeys' => count($journeys),
                'direct' => collect($journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 0),
                'one_transfer' => collect($journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 1),
                'two_transfer' => collect($journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 2),
                'top_trust' => ($journeys[0] ?? null)?->trustState->value,
                'walking_fallback' => collect($journeys)->contains(fn (Journey $journey): bool => collect($journey->legs)->contains(
                    fn ($leg): bool => $leg->type !== JourneyLegType::RIDE && $leg->distanceType === 'STRAIGHT_LINE_ESTIMATE',
                )),
                'disruption_aware' => collect($journeys)->contains(fn (Journey $journey): bool => $journey->disruptions !== []),
                'pedestrian_metrics' => $result->pedestrianMetrics,
                'no_route_message' => $result->noRouteMessage,
            ];
            $rows[] = $row;

            if ($journeys === []) {
                $metrics['unsupported']++;

                continue;
            }
            $metrics['routable']++;
            foreach (['direct', 'one_transfer', 'two_transfer'] as $kind) {
                if ($row[$kind]) {
                    $metrics[$kind]++;
                }
            }
            if ($row['walking_fallback']) {
                $metrics['walk_fallback_used']++;
            }
            if ($row['disruption_aware']) {
                $metrics['disruption_aware']++;
            }
            foreach ($journeys as $journey) {
                $metrics[strtolower($journey->trustState->value)]++;
                if ($this->isSuspicious($journey)) {
                    $metrics['suspicious_journeys']++;
                }
            }
        }

        $gaps = collect($rows)
            ->filter(fn (array $row): bool => $row['status'] !== 'ROUTABLE')
            ->map(fn (array $row): string => $row['origin'].' → '.$row['destination'].' ('.$row['status'].')')
            ->values()
            ->all();

        return [
            'inventory' => $inventory,
            'corridors' => $corridors,
            'matrix' => [
                'rows' => $rows,
                'metrics' => $metrics,
                'top_remaining_gaps' => array_slice($gaps, 0, 8),
            ],
            'bottlenecks' => $this->bottlenecks($inventory, $corridors, $rows),
            'performance' => [
                'elapsed_milliseconds' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'planning_context' => $planningContext->metrics(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function inventory(): array
    {
        $production = [
            'cities' => DB::table('cities')->where('is_demo', false)->where('is_active', true)->count(),
            'places' => DB::table('places')->where('is_demo', false)->where('is_active', true)->count(),
            'aliases' => DB::table('place_aliases')->join('places', 'places.id', '=', 'place_aliases.place_id')->where('places.is_demo', false)->where('places.is_active', true)->where('place_aliases.is_active', true)->count(),
            'stops' => DB::table('stops')->where('is_demo', false)->where('is_active', true)->count(),
            'routes' => DB::table('routes')->where('is_demo', false)->where('is_active', true)->count(),
            'patterns' => DB::table('route_patterns')->where('is_demo', false)->where('is_active', true)->count(),
            'pattern_stops' => DB::table('route_pattern_stops')->join('route_patterns', 'route_patterns.id', '=', 'route_pattern_stops.route_pattern_id')->where('route_patterns.is_demo', false)->where('route_patterns.is_active', true)->count(),
            'transfers' => DB::table('transfer_relationships')->where('is_demo', false)->where('is_active', true)->count(),
            'fares' => DB::table('fares')->where('is_demo', false)->count(),
            'provenance_sources' => DB::table('provenance_sources')->count(),
            'evidence_claims' => DB::table('field_evidence_claims')->count(),
            'active_advisories' => DB::table('disruptions')->where('status', 'ACTIVE')->where('starts_at', '<=', now())->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->count(),
            'public_signals' => DB::table('commuter_reports')->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'ACCEPTED_AS_SIGNAL'])->count(),
        ];
        $demo = [
            'places' => DB::table('places')->where('is_demo', true)->count(),
            'stops' => DB::table('stops')->where('is_demo', true)->count(),
            'routes' => DB::table('routes')->where('is_demo', true)->count(),
            'patterns' => DB::table('route_patterns')->where('is_demo', true)->count(),
            'fares' => DB::table('fares')->where('is_demo', true)->count(),
        ];
        $synthetic = [
            'advisories' => DB::table('disruptions')->where('is_synthetic', true)->count(),
            'public_reports' => DB::table('commuter_reports')->where('is_synthetic', true)->count(),
        ];

        return [
            'production' => $production,
            'demo' => $demo,
            'synthetic' => $synthetic,
            'boarding_precision' => DB::table('stops')->where('is_demo', false)->select('boarding_precision', DB::raw('COUNT(*) AS count'))->groupBy('boarding_precision')->orderBy('boarding_precision')->get()->mapWithKeys(fn ($row): array => [(string) ($row->boarding_precision ?? 'UNKNOWN') => (int) $row->count])->all(),
            'verification' => [
                'routes' => $this->groupedCount('routes'),
                'patterns' => $this->groupedCount('route_patterns'),
                'stops' => $this->groupedCount('stops'),
            ],
        ];
    }

    /** @return array<string, int> */
    private function groupedCount(string $table): array
    {
        return DB::table($table)
            ->where('is_demo', false)
            ->select('verification_state', DB::raw('COUNT(*) AS count'))
            ->groupBy('verification_state')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->verification_state => (int) $row->count])
            ->all();
    }

    /** @param Collection<string, Place> $places @return array<string, mixed> */
    private function corridors($places): array
    {
        $result = [];
        foreach (self::CORRIDORS as $key => $corridor) {
            $known = collect($corridor['slugs'])->map(fn (string $slug) => $places->get($slug))->filter(fn ($place): bool => $place instanceof Place);
            if ($known->isEmpty()) {
                $result[$key] = ['label' => $corridor['label'], 'status' => 'UNKNOWN', 'reason' => 'No supported place anchor is present in the production slice.'];

                continue;
            }
            $placeIds = $known->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $routeCount = DB::table('routes')
                ->join('route_patterns', 'route_patterns.route_id', '=', 'routes.id')
                ->join('route_pattern_stops', 'route_pattern_stops.route_pattern_id', '=', 'route_patterns.id')
                ->join('stops', 'stops.id', '=', 'route_pattern_stops.stop_id')
                ->whereNotNull('stops.location')
                ->whereExists(function ($query) use ($placeIds): void {
                    $query->selectRaw('1')
                        ->from('places AS corridor_places')
                        ->whereIn('corridor_places.id', $placeIds)
                        ->whereRaw('ST_DWithin(stops.location::geography, corridor_places.location::geography, ?)', [1000]);
                })
                ->where('routes.is_active', true)->where('routes.is_demo', false)
                ->where('route_patterns.is_active', true)->where('route_patterns.is_demo', false)
                ->where('stops.is_active', true)->where('stops.is_demo', false)
                ->distinct('routes.id')->count('routes.id');
            $stopCount = DB::table('stops')
                ->whereNotNull('location')
                ->whereExists(function ($query) use ($placeIds): void {
                    $query->selectRaw('1')
                        ->from('places AS corridor_places')
                        ->whereIn('corridor_places.id', $placeIds)
                        ->whereRaw('ST_DWithin(stops.location::geography, corridor_places.location::geography, ?)', [1000]);
                })
                ->where('is_active', true)
                ->where('is_demo', false)
                ->count();
            $status = $routeCount >= 2 ? 'STRONG' : ($routeCount === 1 ? 'PARTIAL' : ($stopCount > 0 ? 'WEAK' : 'UNKNOWN'));
            $result[$key] = [
                'label' => $corridor['label'],
                'status' => $status,
                'place_anchors' => $known->pluck('slug')->values()->all(),
                'mapped_stops' => $stopCount,
                'routes_touching_anchors' => $routeCount,
                'reason' => $routeCount > 0
                    ? $routeCount.' production route(s) touch mapped stops near the selected place anchors.'
                    : 'Place anchors exist, but no production route is linked to a nearby mapped stop.',
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $inventory @param array<string, mixed> $corridors @param list<array<string, mixed>> $rows @return list<string> */
    private function bottlenecks(array $inventory, array $corridors, array $rows): array
    {
        $items = [];
        foreach ($corridors as $corridor) {
            if (($corridor['status'] ?? 'UNKNOWN') !== 'STRONG') {
                $items[] = sprintf('%s: %s (%s)', $corridor['label'], $corridor['status'], $corridor['reason']);
            }
        }
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'ROUTABLE') {
                $items[] = 'Journey coverage: '.$row['origin'].' → '.$row['destination'].' remains '.$row['status'].'.';
            }
        }
        if (($inventory['boarding_precision']['UNKNOWN'] ?? 0) > 0) {
            $items[] = 'Boarding precision: '.$inventory['boarding_precision']['UNKNOWN'].' production stops remain UNKNOWN; review evidence before adding exact guidance.';
        }

        return array_slice($items, 0, 12);
    }

    private function isSuspicious(Journey $journey): bool
    {
        $routeIds = [];
        foreach ($journey->legs as $leg) {
            if ($leg->type !== JourneyLegType::RIDE || $leg->route === null) {
                continue;
            }
            $routeId = (int) ($leg->route['id'] ?? 0);
            if ($routeId > 0 && isset($routeIds[$routeId])) {
                return true;
            }
            $routeIds[$routeId] = true;
            if ($leg->boardingSequence === null || $leg->alightingSequence === null || $leg->alightingSequence <= $leg->boardingSequence) {
                return true;
            }
        }

        return false;
    }
}
