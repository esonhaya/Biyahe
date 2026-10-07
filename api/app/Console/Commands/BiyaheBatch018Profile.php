<?php

namespace App\Console\Commands;

use App\Enums\JourneyLegType;
use App\Enums\VerificationState;
use App\Models\Place;
use App\Services\Routing\Journey;
use App\Services\Routing\JourneyPlanner;
use App\Services\Routing\JourneyPlanningContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BiyaheBatch018Profile extends Command
{
    protected $signature = 'biyahe:batch018:profile
        {--limit=8 : Number of deterministic profiling cases}
        {--case= : Run one named profiling case}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Profile bounded expanded-network planner cases without changing public journey output.';

    /** @var list<array{id: string, origin: string, destination: string}> */
    private const CASES = [
        ['id' => 'colon-ayala', 'origin' => 'colon', 'destination' => 'ayala-center-cebu'],
        ['id' => 'ayala-it-park', 'origin' => 'ayala-center-cebu', 'destination' => 'cebu-it-park'],
        ['id' => 'fuente-talamban', 'origin' => 'fuente-osmena-circle', 'destination' => 'talamban'],
        ['id' => 'it-park-sm', 'origin' => 'cebu-it-park', 'destination' => 'sm-city-cebu'],
        ['id' => 'mabolo-colon', 'origin' => 'mabolo', 'destination' => 'colon'],
        ['id' => 'bulacao-ayala', 'origin' => 'bulacao', 'destination' => 'ayala-center-cebu'],
        ['id' => 'ayala-labangon', 'origin' => 'ayala-center-cebu', 'destination' => 'labangon'],
        ['id' => 'north-reclamation-pardo', 'origin' => 'north-reclamation-area', 'destination' => 'pardo'],
    ];

    public function handle(JourneyPlanner $planner): int
    {
        $selected = collect(self::CASES);
        if ($this->option('case') !== null) {
            $selected = $selected->where('id', (string) $this->option('case'));
        } else {
            $selected = $selected->take(min(max((int) $this->option('limit'), 1), count(self::CASES)));
        }

        $places = Place::query()
            ->where('is_active', true)
            ->where('is_demo', false)
            ->whereIn('verification_state', [VerificationState::VERIFIED->value, VerificationState::UNVERIFIED->value])
            ->when(config('database.default') === 'pgsql', fn (Builder $builder) => $builder->addSelect([
                'places.*',
                DB::raw('ST_AsText(places.location) AS location_wkt'),
            ]))
            ->with('aliases')
            ->get()
            ->keyBy('slug');

        $results = [];
        foreach ($selected as $case) {
            $origin = $places->get($case['origin']);
            $destination = $places->get($case['destination']);
            if (! $origin instanceof Place || ! $destination instanceof Place) {
                $results[] = [
                    'id' => $case['id'],
                    'status' => 'FAILED',
                    'error' => 'Profiling place is not available in the current production slice.',
                ];
                continue;
            }

            $context = new JourneyPlanningContext(
                (int) config('biyahe.routing.max_cached_patterns', 250),
                true,
            );
            $connection = DB::connection();
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $startedAt = hrtime(true);
            $memoryBefore = memory_get_usage(true);
            try {
                $search = $planner->plan($origin, $destination, maxTransfers: 2, context: $context);
                $results[] = [
                    'id' => $case['id'],
                    'origin' => $case['origin'],
                    'destination' => $case['destination'],
                    'status' => 'COMPLETE',
                    'runtime_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                    'query_count' => count($connection->getQueryLog()),
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                    'memory_delta_mb' => round((memory_get_usage(true) - $memoryBefore) / 1048576, 2),
                    'journeys' => count($search->journeys),
                    'direct' => collect($search->journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 0),
                    'one_transfer' => collect($search->journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 1),
                    'two_transfer' => collect($search->journeys)->contains(fn (Journey $journey): bool => $journey->transferCount === 2),
                    'no_route_reason' => $search->noRouteCode,
                    'profile' => $context->metrics(),
                    'semantic_snapshot' => $this->semanticSnapshot($search->journeys),
                ];
            } catch (\Throwable $exception) {
                $results[] = [
                    'id' => $case['id'],
                    'origin' => $case['origin'],
                    'destination' => $case['destination'],
                    'status' => 'FAILED',
                    'runtime_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                    'query_count' => count($connection->getQueryLog()),
                    'error' => substr($exception->getMessage(), 0, 500),
                ];
            } finally {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'profile_version' => 'batch018-v1',
                'cases_defined' => count(self::CASES),
                'cases_executed' => count($results),
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return collect($results)->contains('status', 'FAILED') ? self::FAILURE : self::SUCCESS;
        }

        $this->info('BIYAHE BATCH 018 PLANNER PROFILE');
        $this->line('cases_defined: '.count(self::CASES));
        foreach ($results as $result) {
            $this->line($result['id'].': '.json_encode($result, JSON_UNESCAPED_SLASHES));
        }

        return collect($results)->contains('status', 'FAILED') ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<Journey> $journeys @return list<array<string, mixed>> */
    private function semanticSnapshot(array $journeys): array
    {
        return array_map(static function (Journey $journey): array {
            return [
                'id' => $journey->id,
                'trust' => $journey->trustState->value,
                'transfers' => $journey->transferCount,
                'walking_distance_meters' => round($journey->walkingDistanceMeters, 2),
                'legs' => array_values(array_map(
                    static fn ($leg): array => [
                        'type' => $leg->type->value,
                        'route' => $leg->route['code'] ?? null,
                        'pattern' => $leg->pattern['id'] ?? null,
                        'boarding' => $leg->boardingStop['id'] ?? $leg->origin['id'] ?? null,
                        'alighting' => $leg->alightingStop['id'] ?? $leg->destination['id'] ?? null,
                        'boarding_sequence' => $leg->boardingSequence,
                        'alighting_sequence' => $leg->alightingSequence,
                        'distance_type' => $leg->distanceType,
                    ],
                    array_filter($journey->legs, static fn ($leg): bool => in_array($leg->type, [JourneyLegType::RIDE, JourneyLegType::TRANSFER_WALK], true)),
                )),
            ];
        }, array_slice($journeys, 0, 10));
    }
}
