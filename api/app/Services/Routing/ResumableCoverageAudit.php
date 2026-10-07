<?php

namespace App\Services\Routing;

use App\Enums\JourneyLegType;
use App\Enums\VerificationState;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ResumableCoverageAudit
{
    public const VERSION = 'batch018-coverage-v1';

    public function __construct(private readonly JourneyPlanner $planner) {}

    /** @return list<array{id: string, origin: string, destination: string}> */
    public function workUnits(): array
    {
        return array_map(
            fn (array $pair, int $index): array => [
                'id' => sprintf('%02d-%s-to-%s', $index + 1, $pair['origin'], $pair['destination']),
                ...$pair,
            ],
            NetworkReleaseAudit::matrix(),
            array_keys(NetworkReleaseAudit::matrix()),
        );
    }

    /** @return Collection<string, Place> */
    public function places(): Collection
    {
        return Place::query()
            ->with(['aliases', 'city', 'provenanceSource'])
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
            ->when(config('database.default') === 'pgsql', fn (Builder $builder) => $builder->addSelect([
                'places.*',
                DB::raw('ST_AsText(places.location) AS location_wkt'),
            ]))
            ->orderBy('city_id')
            ->orderBy('id')
            ->get()
            ->keyBy('slug');
    }

    public function fingerprint(): string
    {
        $counts = [
            'places' => DB::table('places')->where('is_demo', false)->where('is_active', true)->count(),
            'stops' => DB::table('stops')->where('is_demo', false)->where('is_active', true)->count(),
            'routes' => DB::table('routes')->where('is_demo', false)->where('is_active', true)->count(),
            'patterns' => DB::table('route_patterns')->where('is_demo', false)->where('is_active', true)->count(),
            'pattern_stops' => DB::table('route_pattern_stops')
                ->join('route_patterns', 'route_patterns.id', '=', 'route_pattern_stops.route_pattern_id')
                ->where('route_patterns.is_demo', false)
                ->where('route_patterns.is_active', true)
                ->count(),
            'active_pedestrian_dataset' => DB::table('pedestrian_network_datasets')->where('is_active', true)->value('stable_id'),
            'atlas_checked_at' => DB::table('provenance_sources')->where('stable_id', 'source-osm-cebu-batch017')->value('checked_at'),
        ];

        return hash('sha256', json_encode([
            'version' => self::VERSION,
            'matrix' => NetworkReleaseAudit::matrix(),
            'counts' => $counts,
            'max_transfers' => (int) config('biyahe.routing.default_max_transfers', 2),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function run(array $unit, Collection $places, ?JourneyPlanningContext $context = null): array
    {
        $startedAt = hrtime(true);
        $origin = $places->get($unit['origin']);
        $destination = $places->get($unit['destination']);
        if (! $origin instanceof Place || ! $destination instanceof Place) {
            return [
                ...$unit,
                'status' => 'FAILED',
                'started_at' => now()->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'runtime_ms' => 0,
                'error' => 'PLACE_NOT_FOUND',
            ];
        }

        try {
            $search = $this->planner->plan(
                $origin,
                $destination,
                maxTransfers: (int) config('biyahe.routing.default_max_transfers', 2),
                context: $context ?? new JourneyPlanningContext((int) config('biyahe.routing.max_cached_patterns', 250)),
            );
            $journeys = $search->journeys;
            $trustCounts = ['VERIFIED' => 0, 'MIXED' => 0, 'UNVERIFIED' => 0];
            $journeyRefs = [];
            $suspicious = [];
            $signatures = [];
            foreach ($journeys as $journey) {
                $trustCounts[$journey->trustState->value] = ($trustCounts[$journey->trustState->value] ?? 0) + 1;
                $signature = collect($journey->legs)
                    ->filter(static fn ($leg): bool => $leg->type === JourneyLegType::RIDE)
                    ->map(static fn ($leg): string => implode(':', [
                        $leg->route['id'] ?? 'none',
                        $leg->pattern['id'] ?? 'none',
                        $leg->boardingStop['id'] ?? 'none',
                        $leg->alightingStop['id'] ?? 'none',
                    ]))
                    ->implode('|');
                if (isset($signatures[$signature])) {
                    $suspicious[] = 'DUPLICATE';
                }
                $signatures[$signature] = true;
                if ($journey->transferCount > (int) config('biyahe.routing.public_max_transfers', 2)) {
                    $suspicious[] = 'TRANSFER_CAP';
                }
                $rides = [];
                foreach ($journey->legs as $leg) {
                    if ($leg->type === JourneyLegType::RIDE) {
                        if ($leg->boardingSequence !== null && $leg->alightingSequence !== null
                            && $leg->boardingSequence >= $leg->alightingSequence) {
                            $suspicious[] = 'BACKWARDS';
                        }
                        $rides[] = [
                            'route_id' => $leg->route['id'] ?? null,
                            'route_code' => $leg->route['code'] ?? null,
                            'pattern_id' => $leg->pattern['id'] ?? null,
                            'boarding_stop_id' => $leg->boardingStop['id'] ?? null,
                            'alighting_stop_id' => $leg->alightingStop['id'] ?? null,
                            'boarding_sequence' => $leg->boardingSequence,
                            'alighting_sequence' => $leg->alightingSequence,
                        ];
                    }
                }
                $journeyRefs[] = [
                    'id' => $journey->id,
                    'trust' => $journey->trustState->value,
                    'transfers' => $journey->transferCount,
                    'fallback_walk' => collect($journey->legs)->contains(
                        static fn ($leg): bool => $leg->distanceType === 'STRAIGHT_LINE_ESTIMATE',
                    ),
                    'rides' => $rides,
                ];
            }
            $walkFallback = collect($journeyRefs)->where('fallback_walk', true)->count();

            return [
                ...$unit,
                'status' => 'COMPLETE',
                'started_at' => now()->subMilliseconds((int) round((hrtime(true) - $startedAt) / 1_000_000))->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'runtime_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'journey_count' => count($journeys),
                'direct' => collect($journeys)->contains(static fn ($journey): bool => $journey->transferCount === 0),
                'one_transfer' => collect($journeys)->contains(static fn ($journey): bool => $journey->transferCount === 1),
                'two_transfer' => collect($journeys)->contains(static fn ($journey): bool => $journey->transferCount === 2),
                'no_route_reason' => $search->noRouteCode,
                'trust_counts' => $trustCounts,
                'network_walk' => count($journeys) > 0 && $walkFallback < count($journeys),
                'fallback_walk' => $walkFallback > 0,
                'suspicious_flags' => array_values(array_unique($suspicious)),
                'journey_refs' => array_slice($journeyRefs, 0, 10),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                ...$unit,
                'status' => 'FAILED',
                'started_at' => now()->subMilliseconds((int) round((hrtime(true) - $startedAt) / 1_000_000))->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'runtime_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'journey_count' => 0,
                'suspicious_flags' => [],
                'error' => substr($exception->getMessage(), 0, 500),
            ];
        }
    }

    /** @param array<string, array<string, mixed>> $results @return array<string, mixed> */
    public function summarize(array $results): array
    {
        $complete = array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'COMPLETE');
        $failed = array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'FAILED');
        $trust = ['VERIFIED' => 0, 'MIXED' => 0, 'UNVERIFIED' => 0];
        $summary = [
            'pairs_total' => count($this->workUnits()),
            'pairs_complete' => count($complete),
            'pairs_failed' => count($failed),
            'routable' => 0,
            'direct' => 0,
            'one_transfer' => 0,
            'two_transfer' => 0,
            'no_nearby_boarding' => 0,
            'no_walkable_access' => 0,
            'no_known_journey' => 0,
            'temporarily_unavailable' => 0,
            'network_walk' => 0,
            'fallback_walk' => 0,
            'suspicious_journeys' => 0,
            'trust' => $trust,
        ];
        foreach ($complete as $result) {
            if (($result['journey_count'] ?? 0) > 0) {
                $summary['routable']++;
            }
            foreach (['direct', 'one_transfer', 'two_transfer', 'fallback_walk', 'network_walk'] as $key) {
                if (! empty($result[$key])) {
                    $summary[$key]++;
                }
            }
            $code = $result['no_route_reason'] ?? null;
            if ($code === 'NO_NEARBY_BOARDING' || $code === 'NO_NEARBY_ALIGHTING') {
                $summary['no_nearby_boarding']++;
            } elseif ($code === 'NO_WALKABLE_ACCESS') {
                $summary['no_walkable_access']++;
            } elseif ($code === 'TEMPORARILY_UNAVAILABLE') {
                $summary['temporarily_unavailable']++;
            } elseif ($code === 'NO_KNOWN_JOURNEY' || $code === 'ROUTING_DATA_NOT_READY') {
                $summary['no_known_journey']++;
            }
            foreach (($result['trust_counts'] ?? []) as $state => $count) {
                $summary['trust'][$state] = ($summary['trust'][$state] ?? 0) + (int) $count;
            }
            $summary['suspicious_journeys'] += count($result['suspicious_flags'] ?? []);
        }

        return $summary;
    }
}
