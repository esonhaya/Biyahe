<?php

namespace App\Services\Routing;

use App\Enums\JourneyLegType;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NetworkAudit
{
    public function __construct(private readonly JourneyPlanner $planner) {}

    /** @return array<string, mixed> */
    public function audit(?int $maxPlaces = null, ?int $maxPairs = null): array
    {
        $maxPlaces ??= (int) config('biyahe.routing.network_audit_max_places', 40);
        $maxPairs ??= (int) config('biyahe.routing.network_audit_max_pairs', 200);
        $maxPlaces = min(max($maxPlaces, 2), 100);
        $maxPairs = min(max($maxPairs, 1), 1000);

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
            'pairs_examined' => 0,
            'routable' => 0,
            'direct' => 0,
            'one_transfer' => 0,
            'no_known_journey' => 0,
            'coverage_gap_pairs' => 0,
            'warnings' => [],
            'errors' => [],
        ];

        foreach ($places as $cityPlaces) {
            foreach ($cityPlaces as $origin) {
                foreach ($cityPlaces as $destination) {
                    if ($metrics['pairs_examined'] >= $maxPairs || $origin->getKey() === $destination->getKey()) {
                        continue;
                    }
                    $metrics['pairs_examined']++;
                    $result = $this->planner->plan($origin, $destination);
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
                    $this->checkJourneys($result->journeys, $metrics);
                }
                if ($metrics['pairs_examined'] >= $maxPairs) {
                    break 2;
                }
            }
        }

        return $metrics;
    }

    /** @param list<Journey> $journeys @param array<string, mixed> $metrics */
    private function checkJourneys(array $journeys, array &$metrics): void
    {
        $ids = [];
        $signatures = [];
        $maximumWalking = ((float) config('biyahe.routing.max_access_radius_meters', 1000) * 2)
            + (float) config('biyahe.routing.max_transfer_radius_meters', 600);
        foreach ($journeys as $journey) {
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
            }
            $signatures[$signature] = $journey->id;
            if ($journey->transferCount > 1) {
                $metrics['errors'][] = 'journey '.$journey->id.' exceeds one transfer';
            }
            if ($journey->walkingDistanceMeters > $maximumWalking) {
                $metrics['warnings'][] = 'journey '.$journey->id.' exceeds bounded walking burden';
            }
            if (! in_array($journey->trustState->value, ['VERIFIED', 'MIXED', 'UNVERIFIED'], true)) {
                $metrics['errors'][] = 'journey '.$journey->id.' has invalid trust state';
            }
            if ($journey->trustState->value === 'VERIFIED' && collect($journey->legs)->contains(
                fn ($leg): bool => $leg->trust !== null && $leg->trust->value !== 'VERIFIED',
            )) {
                $metrics['errors'][] = 'journey '.$journey->id.' escalates a non-VERIFIED leg to VERIFIED';
            }
            $routeIds = [];
            foreach ($journey->legs as $leg) {
                if ($leg->type !== JourneyLegType::RIDE) {
                    continue;
                }
                $routeId = $leg->route['id'] ?? null;
                if ($routeId !== null && isset($routeIds[$routeId])) {
                    $metrics['errors'][] = 'journey '.$journey->id.' reuses route '.$routeId.' across transit legs';
                }
                if ($routeId !== null) {
                    $routeIds[$routeId] = true;
                }
                foreach ([$leg->route['verification']['state'] ?? null, $leg->pattern['verification']['state'] ?? null] as $state) {
                    if (in_array($state, ['STALE', 'RETIRED'], true)) {
                        $metrics['errors'][] = 'journey '.$journey->id.' exposes '.$state.' service data';
                    }
                }
                if ($leg->boardingSequence === null || $leg->alightingSequence === null || $leg->boardingSequence >= $leg->alightingSequence) {
                    $metrics['errors'][] = 'journey '.$journey->id.' contains a backwards or empty ride';
                }
            }
        }
    }
}
