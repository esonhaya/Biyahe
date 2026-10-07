<?php

namespace App\Services\Routing;

use App\Models\RoutePattern;
use App\Models\TransferRelationship;
use App\Support\Trust;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class JourneyPlanningContext
{
    /** @var array<int, RoutePattern> */
    private array $patterns = [];

    /** @var array<int, Collection<int, RoutePattern>> */
    private array $networkPatterns = [];

    /** @var array<int, array<int, array{boarding: bool, alighting: bool, pattern_id: int}>> */
    private array $networkStopIndex = [];

    /** @var array<int, Collection<int, TransferRelationship>> */
    private array $transferRelationships = [];

    private int $patternLoadBatches = 0;

    private int $patternModelsLoaded = 0;

    private int $transferSnapshotsLoaded = 0;

    /** @var array<string, bool> */
    private array $routability = [];

    /** @var array<string, string|null> */
    private array $effectiveStates = [];

    /** @var array<string, string|null> */
    private array $effectiveFactStates = [];

    /** @var array<string, string> */
    private array $effectiveFactSetStates = [];

    private int $trustCacheHits = 0;

    /** @var array<string, float> */
    private array $timings = [];

    /** @var array<string, int|float> */
    private array $counters = [];

    public function __construct(
        private readonly int $maxCachedPatterns = 250,
        private readonly bool $profiling = false,
    ) {}

    public function profilingEnabled(): bool
    {
        return $this->profiling;
    }

    /**
     * Measure an optional developer-only stage without changing the planner's
     * normal response shape.
     */
    public function measure(string $stage, Closure $callback): mixed
    {
        if (! $this->profiling) {
            return $callback();
        }

        $startedAt = hrtime(true);
        try {
            return $callback();
        } finally {
            $this->timings[$stage] = ($this->timings[$stage] ?? 0.0)
                + ((hrtime(true) - $startedAt) / 1_000_000);
        }
    }

    public function increment(string $counter, int $amount = 1): void
    {
        if (! $this->profiling) {
            return;
        }

        $this->counters[$counter] = ($this->counters[$counter] ?? 0) + $amount;
    }

    public function setCounter(string $counter, int|float $value): void
    {
        if ($this->profiling) {
            $this->counters[$counter] = $value;
        }
    }

    /**
     * Load the canonical active network once for a planner request. The
     * index is derived from the same RoutePattern models used by the planner;
     * it is not a second transport-data source.
     *
     * @param  Closure(): iterable<RoutePattern>  $loader
     * @return Collection<int, RoutePattern>|null
     */
    public function matchingPatterns(
        int $cityId,
        array $boardingStopIds,
        array $alightingStopIds,
        bool $requireBoarding,
        Closure $loader,
        int $limit,
    ): ?Collection {
        if (! array_key_exists($cityId, $this->networkPatterns)) {
            $loaded = collect($loader())->keyBy(fn (RoutePattern $pattern): int => (int) $pattern->getKey());
            if ($loaded->count() > $this->maxCachedPatterns) {
                // The caller can fall back to the bounded SQL query when the
                // configured in-request index ceiling is exceeded.
                return null;
            }

            $this->networkPatterns[$cityId] = $loaded;
            $this->patternLoadBatches++;
            $this->patternModelsLoaded += $loaded->count();
            foreach ($loaded as $pattern) {
                foreach ($pattern->patternStops as $association) {
                    $stopId = (int) $association->stop_id;
                    $this->networkStopIndex[$cityId][$stopId][] = [
                        'boarding' => (bool) $association->boarding_allowed,
                        'alighting' => (bool) $association->alighting_allowed,
                        'pattern_id' => (int) $pattern->getKey(),
                    ];
                }
            }
            $this->increment('network_index_patterns', $loaded->count());
            $this->increment('network_index_stops', count($this->networkStopIndex[$cityId] ?? []));
        }

        $patterns = $this->networkPatterns[$cityId] ?? collect();
        if ($patterns->isEmpty()) {
            return collect();
        }

        $boardingIds = array_fill_keys(array_map('intval', $boardingStopIds), true);
        $alightingIds = array_fill_keys(array_map('intval', $alightingStopIds), true);
        $candidateIds = null;
        foreach ($this->networkStopIndex[$cityId] ?? [] as $stopId => $entries) {
            $matchesBoarding = isset($boardingIds[$stopId])
                && collect($entries)->contains(static fn (array $entry): bool => $entry['boarding']);
            $matchesAlighting = isset($alightingIds[$stopId])
                && collect($entries)->contains(static fn (array $entry): bool => $entry['alighting']);
            if (($requireBoarding && $matchesBoarding) || $matchesAlighting) {
                foreach ($entries as $entry) {
                    if (($requireBoarding && isset($boardingIds[$stopId]) && $entry['boarding'])
                        || (isset($alightingIds[$stopId]) && $entry['alighting'])) {
                        $candidateIds[$entry['pattern_id']] = true;
                    }
                }
            }
        }

        $filtered = $patterns->filter(function (RoutePattern $pattern) use ($boardingIds, $alightingIds, $requireBoarding): bool {
            $hasBoarding = ! $requireBoarding;
            $hasAlighting = $alightingIds === [];
            foreach ($pattern->patternStops as $association) {
                $stopId = (int) $association->stop_id;
                $hasBoarding = $hasBoarding || (isset($boardingIds[$stopId]) && (bool) $association->boarding_allowed);
                $hasAlighting = $hasAlighting || (isset($alightingIds[$stopId]) && (bool) $association->alighting_allowed);
                if ($hasBoarding && $hasAlighting) {
                    return true;
                }
            }

            return $hasBoarding && $hasAlighting;
        });

        if ($candidateIds !== null) {
            $filtered = $filtered->filter(fn (RoutePattern $pattern): bool => isset($candidateIds[(int) $pattern->getKey()]));
        }

        $this->increment('network_index_hits');

        return $filtered
            ->sort(function (RoutePattern $left, RoutePattern $right): int {
                return ((int) $left->route_id <=> (int) $right->route_id)
                    ?: ((int) $left->getKey() <=> (int) $right->getKey());
            })
            ->take($limit)
            ->values();
    }

    /**
     * @param  list<int>  $patternIds
     * @param  Closure(list<int>): iterable<RoutePattern>  $loader
     * @return Collection<int, RoutePattern>
     */
    public function patterns(array $patternIds, Closure $loader): Collection
    {
        $patternIds = array_values(array_unique(array_map('intval', $patternIds)));
        $missing = array_values(array_diff($patternIds, array_keys($this->patterns)));
        $loadedById = collect();

        if ($missing !== []) {
            $this->patternLoadBatches++;
            $loadedById = collect($loader($missing))->keyBy(fn (RoutePattern $pattern): int => (int) $pattern->getKey());
            $this->patternModelsLoaded += $loadedById->count();

            if (count($this->patterns) + count($missing) <= $this->maxCachedPatterns) {
                foreach ($loadedById as $id => $pattern) {
                    $this->patterns[(int) $id] = $pattern;
                }
            }
        }

        return collect($patternIds)
            ->map(fn (int $id): ?RoutePattern => $this->patterns[$id] ?? $loadedById->get($id))
            ->filter(fn ($pattern): bool => $pattern instanceof RoutePattern)
            ->values();
    }

    /**
     * @param  Closure(): Collection<int, TransferRelationship>  $loader
     * @return Collection<int, TransferRelationship>
     */
    public function transferRelationships(int $cityId, Closure $loader): Collection
    {
        if (! array_key_exists($cityId, $this->transferRelationships)) {
            $this->transferRelationships[$cityId] = $loader();
            $this->transferSnapshotsLoaded++;
        }

        return $this->transferRelationships[$cityId];
    }

    public function effectiveState(Model $model): ?string
    {
        $key = $this->modelKey($model);
        if (array_key_exists($key, $this->effectiveStates)) {
            $this->trustCacheHits++;

            return $this->effectiveStates[$key];
        }

        return $this->effectiveStates[$key] = Trust::effectiveState($model);
    }

    public function effectiveFactState(Model $model, string $fact): ?string
    {
        $key = $this->modelKey($model).':'.$fact;
        if (array_key_exists($key, $this->effectiveFactStates)) {
            $this->trustCacheHits++;

            return $this->effectiveFactStates[$key];
        }

        return $this->effectiveFactStates[$key] = Trust::effectiveFactState($model, $fact);
    }

    /** @param list<string> $facts */
    public function effectiveFactSetState(Model $model, array $facts): string
    {
        sort($facts);
        $key = $this->modelKey($model).':'.implode(',', $facts);
        if (array_key_exists($key, $this->effectiveFactSetStates)) {
            $this->trustCacheHits++;

            return $this->effectiveFactSetStates[$key];
        }

        return $this->effectiveFactSetStates[$key] = Trust::effectiveFactSetState($model, $facts);
    }

    public function isRoutable(Model $model): bool
    {
        $key = 'model:'.$this->modelKey($model);
        if (array_key_exists($key, $this->routability)) {
            $this->trustCacheHits++;

            return $this->routability[$key];
        }

        return $this->routability[$key] = Trust::isRoutable($model);
    }

    /** @param list<string> $facts */
    public function isFactRoutable(Model $model, array $facts): bool
    {
        sort($facts);
        $key = 'facts:'.$this->modelKey($model).':'.implode(',', $facts);
        if (array_key_exists($key, $this->routability)) {
            $this->trustCacheHits++;

            return $this->routability[$key];
        }

        return $this->routability[$key] = Trust::isFactRoutable($model, $facts);
    }

    private function modelKey(Model $model): string
    {
        return $model->getConnectionName().':'.$model->getTable().':'.$model->getKey();
    }

    /** @return array{pattern_load_batches: int, pattern_models_loaded: int, cached_patterns: int, transfer_snapshots_loaded: int, trust_cache_entries: int, trust_cache_hits: int} */
    public function metrics(): array
    {
        $metrics = [
            'pattern_load_batches' => $this->patternLoadBatches,
            'pattern_models_loaded' => $this->patternModelsLoaded,
            'cached_patterns' => count($this->patterns),
            'transfer_snapshots_loaded' => $this->transferSnapshotsLoaded,
            'trust_cache_entries' => count($this->routability) + count($this->effectiveStates)
                + count($this->effectiveFactStates) + count($this->effectiveFactSetStates),
            'trust_cache_hits' => $this->trustCacheHits,
        ];

        if ($this->profiling) {
            $metrics['profile'] = [
                'timings_ms' => array_map(static fn (float $value): float => round($value, 2), $this->timings),
                'counters' => $this->counters,
            ];
        }

        return $metrics;
    }
}
