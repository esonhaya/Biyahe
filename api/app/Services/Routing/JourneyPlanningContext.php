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

    public function __construct(private readonly int $maxCachedPatterns = 250) {}

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
        return [
            'pattern_load_batches' => $this->patternLoadBatches,
            'pattern_models_loaded' => $this->patternModelsLoaded,
            'cached_patterns' => count($this->patterns),
            'transfer_snapshots_loaded' => $this->transferSnapshotsLoaded,
            'trust_cache_entries' => count($this->routability) + count($this->effectiveStates)
                + count($this->effectiveFactStates) + count($this->effectiveFactSetStates),
            'trust_cache_hits' => $this->trustCacheHits,
        ];
    }
}
