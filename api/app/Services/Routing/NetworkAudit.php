<?php

namespace App\Services\Routing;

use App\Enums\JourneyLegType;
use App\Models\Place;
use App\Models\Route;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NetworkAudit
{
    public function __construct(
        private readonly JourneyPlanner $planner,
        private readonly CanIRideChecker $canIRideChecker,
    ) {}

    /** @return array<string, mixed> */
    public function audit(
        ?int $maxPlaces = null,
        ?int $maxPairs = null,
        ?int $maxTransfers = null,
        ?int $maxCanIRideChecks = null,
    ): array {
        $maxPlaces ??= (int) config('biyahe.routing.network_audit_max_places', 40);
        $maxPairs ??= (int) config('biyahe.routing.network_audit_max_pairs', 200);
        $maxPlaces = min(max($maxPlaces, 2), 100);
        $maxPairs = min(max($maxPairs, 1), 1000);
        $maxTransfers = min(max($maxTransfers ?? (int) config('biyahe.routing.default_max_transfers', 2), 0), (int) config('biyahe.routing.public_max_transfers', 2));
        $maxCanIRideChecks = min(max($maxCanIRideChecks ?? (int) config('biyahe.routing.network_audit_max_can_i_ride_checks', 24), 1), 100);

        $places = Place::query()
            ->with(['aliases', 'provenanceSource'])
            ->where('places.is_active', true)
            ->where('places.is_demo', false)
            ->whereIn('places.verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->when(config('database.default') === 'pgsql', fn (Builder $builder) => $builder->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]))
            ->orderBy('places.city_id')
            ->orderBy('places.id')
            ->limit($maxPlaces)
            ->get()
            ->groupBy('city_id');

        $metrics = [
            'max_transfers' => $maxTransfers,
            'max_can_i_ride_checks' => $maxCanIRideChecks,
            'pairs_examined' => 0,
            'routable' => 0,
            'direct' => 0,
            'one_transfer' => 0,
            'two_transfer' => 0,
            'no_known_journey' => 0,
            'coverage_gap_pairs' => 0,
            'trust_distribution' => ['VERIFIED' => 0, 'MIXED' => 0, 'UNVERIFIED' => 0],
            'walking_bound_violations' => 0,
            'loops' => 0,
            'duplicate_journeys' => 0,
            'direction_violations' => 0,
            'transfer_cap_violations' => 0,
            'stale_leakage' => 0,
            'trust_leakage' => 0,
            'planner_errors' => 0,
            'can_i_ride_integrity_errors' => 0,
            'can_i_ride_checked' => 0,
            'can_i_ride_yes' => 0,
            'can_i_ride_no' => 0,
            'can_i_ride_direction_dependent' => 0,
            'can_i_ride_unknown' => 0,
            '_transfer_counts' => [],
            'warnings' => [],
            'errors' => [],
        ];
        $auditedPairs = [];

        foreach ($places as $cityPlaces) {
            foreach ($cityPlaces as $origin) {
                foreach ($cityPlaces as $destination) {
                    if ($metrics['pairs_examined'] >= $maxPairs || $origin->getKey() === $destination->getKey()) {
                        continue;
                    }
                    $metrics['pairs_examined']++;
                    $auditedPairs[] = [$origin, $destination];
                    $result = $this->planner->plan($origin, $destination, maxTransfers: $maxTransfers);
                    if (! $result->hasJourneys()) {
                        $metrics['no_known_journey']++;
                        $metrics['coverage_gap_pairs']++;

                        continue;
                    }

                    $metrics['routable']++;
                    if (collect($result->journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 0)) {
                        $metrics['direct']++;
                    }
                    if (collect($result->journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 1)) {
                        $metrics['one_transfer']++;
                    }
                    if (collect($result->journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 2)) {
                        $metrics['two_transfer']++;
                    }
                    $this->checkJourneys($result->journeys, $metrics);
                }
                if ($metrics['pairs_examined'] >= $maxPairs) {
                    break 2;
                }
            }
        }

        $this->checkCanIRide($auditedPairs, $metrics, $maxCanIRideChecks);
        $transferCounts = $metrics['_transfer_counts'];
        sort($transferCounts);
        $metrics['average_transfers'] = $transferCounts === [] ? 0 : round(array_sum($transferCounts) / count($transferCounts), 2);
        $metrics['median_transfers'] = $transferCounts === []
            ? 0
            : $transferCounts[(int) floor((count($transferCounts) - 1) / 2)];
        unset($metrics['_transfer_counts']);
        $metrics['planner_errors'] = count($metrics['errors']);

        return $metrics;
    }

    /** @param list<Journey> $journeys @param array<string, mixed> $metrics */
    private function checkJourneys(array $journeys, array &$metrics): void
    {
        $ids = [];
        $signatures = [];
        $maximumWalking = ((float) config('biyahe.routing.max_access_radius_meters', 1000) * 2)
            + ((float) config('biyahe.routing.max_transfer_radius_meters', 600)
                * (int) config('biyahe.routing.default_max_transfers', 2));
        foreach ($journeys as $journey) {
            $metrics['_transfer_counts'][] = $journey->transferCount;
            $metrics['trust_distribution'][$journey->trustState->value]++;
            if (isset($ids[$journey->id])) {
                $metrics['errors'][] = 'duplicate journey id '.$journey->id;
            }
            $ids[$journey->id] = true;
            $signature = collect($journey->legs)
                ->filter(fn ($leg): bool => $leg->type === JourneyLegType::RIDE)
                ->map(fn ($leg): string => implode(':', [
                    $leg->route['id'] ?? 'unknown',
                    $leg->pattern['id'] ?? 'unknown',
                    $leg->boardingStop['id'] ?? 'unknown',
                    $leg->alightingStop['id'] ?? 'unknown',
                ]))
                ->implode('|');
            if (isset($signatures[$signature])) {
                $metrics['warnings'][] = 'journey '.$journey->id.' duplicates transit legs from '.$signatures[$signature];
                $metrics['duplicate_journeys']++;
            }
            $signatures[$signature] = $journey->id;
            if ($journey->transferCount > (int) config('biyahe.routing.public_max_transfers', 2)) {
                $metrics['errors'][] = 'journey '.$journey->id.' exceeds the configured transfer cap';
                $metrics['transfer_cap_violations']++;
            }
            if ($journey->walkingDistanceMeters > $maximumWalking) {
                $metrics['warnings'][] = 'journey '.$journey->id.' exceeds bounded walking burden';
                $metrics['walking_bound_violations']++;
            }
            if (! in_array($journey->trustState->value, ['VERIFIED', 'MIXED', 'UNVERIFIED'], true)) {
                $metrics['errors'][] = 'journey '.$journey->id.' has invalid trust state';
            }
            if ($journey->trustState->value === 'VERIFIED' && collect($journey->legs)->contains(
                fn ($leg): bool => $leg->trust !== null && $leg->trust->value !== 'VERIFIED',
            )) {
                $metrics['errors'][] = 'journey '.$journey->id.' escalates a non-VERIFIED leg to VERIFIED';
                $metrics['trust_leakage']++;
            }
            $routeIds = [];
            foreach ($journey->legs as $leg) {
                if ($leg->type !== JourneyLegType::RIDE) {
                    continue;
                }
                $routeId = $leg->route['id'] ?? null;
                if ($routeId !== null && isset($routeIds[$routeId])) {
                    $metrics['errors'][] = 'journey '.$journey->id.' reuses route '.$routeId.' across transit legs';
                    $metrics['loops']++;
                }
                if ($routeId !== null) {
                    $routeIds[$routeId] = true;
                }
                foreach ([
                    $leg->route['verification']['state'] ?? null,
                    $leg->pattern['verification']['state'] ?? null,
                    $leg->boardingStop['verification']['state'] ?? null,
                    $leg->alightingStop['verification']['state'] ?? null,
                    $leg->boardingAssociation['state'] ?? null,
                    $leg->alightingAssociation['state'] ?? null,
                ] as $state) {
                    if (in_array($state, ['STALE', 'RETIRED'], true)) {
                        $metrics['errors'][] = 'journey '.$journey->id.' exposes '.$state.' service data';
                        $metrics['stale_leakage']++;
                    }
                }
                if ($leg->boardingSequence === null || $leg->alightingSequence === null || $leg->boardingSequence >= $leg->alightingSequence) {
                    $metrics['errors'][] = 'journey '.$journey->id.' contains a backwards or empty ride';
                    $metrics['direction_violations']++;
                }
            }
        }
    }

    /** @param list<array{0: Place, 1: Place}> $pairs @param array<string, mixed> $metrics */
    private function checkCanIRide(array $pairs, array &$metrics, int $maximumChecks): void
    {
        $routes = Route::query()
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->orderBy('id')
            ->limit((int) config('biyahe.routing.max_pattern_candidates', 50))
            ->get(['id', 'code']);
        foreach ($pairs as [$origin, $destination]) {
            foreach ($routes as $route) {
                if ($metrics['can_i_ride_checked'] >= $maximumChecks) {
                    return;
                }
                $result = $this->canIRideChecker->check($origin, $destination, routeCode: $route->code);
                $metrics['can_i_ride_checked']++;
                match ($result->status) {
                    'YES' => $metrics['can_i_ride_yes']++,
                    'NO' => $metrics['can_i_ride_no']++,
                    'DIRECTION_DEPENDENT' => $metrics['can_i_ride_direction_dependent']++,
                    default => $metrics['can_i_ride_unknown']++,
                };
                if ($result->status === 'YES') {
                    $option = $result->option;
                    $optionStates = is_array($option) ? [
                        $result->route['verification']['state'] ?? null,
                        $option['pattern']['verification']['state'] ?? null,
                        $option['boarding_stop']['verification']['state'] ?? null,
                        $option['alighting_stop']['verification']['state'] ?? null,
                        $option['boarding_association']['state'] ?? null,
                        $option['alighting_association']['state'] ?? null,
                    ] : [];
                    if (
                        ! is_array($option)
                        || ($option['boarding_sequence'] ?? 0) >= ($option['alighting_sequence'] ?? 0)
                        || (($option['trust'] ?? null) === 'VERIFIED' && collect($optionStates)->contains(fn (?string $state): bool => $state !== 'VERIFIED'))
                    ) {
                        $metrics['can_i_ride_integrity_errors']++;
                        $metrics['errors'][] = 'can-I-ride returned an invalid YES for route '.$route->code.' between places '.$origin->getKey().' and '.$destination->getKey();
                    }
                }
            }
        }
    }
}
