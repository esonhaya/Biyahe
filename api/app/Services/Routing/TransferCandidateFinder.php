<?php

namespace App\Services\Routing;

use App\Enums\JourneyTrustState;
use App\Enums\VerificationState;
use App\Models\Stop;
use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TransferCandidateFinder
{
    /** @return Collection<int, array<string, mixed>> */
    public function find(?int $cityId = null, ?float $radiusMeters = null, ?int $limit = null): Collection
    {
        $radiusMeters ??= (float) config('biyahe.routing.max_transfer_radius_meters', 600);
        $radiusMeters = min(max($radiusMeters, 1), (float) config('biyahe.routing.max_transfer_radius_meters', 600));
        $limit ??= (int) config('biyahe.routing.transfer_candidate_stop_limit', 200);
        $limit = min(max($limit, 1), 500);

        $query = Stop::query()
            ->with([
                'routePatterns' => function (BelongsToMany $relation): void {
                    $relation
                        ->where('route_patterns.is_active', true)
                        ->where('route_patterns.is_demo', false)
                        ->whereIn('route_patterns.verification_state', ['VERIFIED', 'UNVERIFIED'])
                        ->wherePivotIn('verification_state', ['VERIFIED', 'UNVERIFIED'])
                        ->with('route');
                },
            ])
            ->where('stops.is_active', true)
            ->where('stops.is_demo', false)
            ->whereIn('stops.verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->whereNotNull('stops.location')
            ->when($cityId !== null, fn (Builder $builder) => $builder->where('stops.city_id', $cityId))
            ->orderBy('stops.id')
            ->limit($limit);
        if (config('database.default') === 'pgsql') {
            $query->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')]);
        }

        $stops = $query->get();
        $candidates = collect();
        foreach ($stops as $index => $fromStop) {
            if (! $fromStop instanceof Stop || $fromStop->location_point === null) {
                continue;
            }
            $fromRoutes = $fromStop->routePatterns->map(fn ($pattern): ?int => $pattern->route_id)->filter()->unique();
            if ($fromRoutes->isEmpty()) {
                continue;
            }
            for ($nextIndex = $index + 1; $nextIndex < $stops->count(); $nextIndex++) {
                $toStop = $stops->get($nextIndex);
                if (! $toStop instanceof Stop || $toStop->location_point === null || $fromStop->city_id !== $toStop->city_id) {
                    continue;
                }
                $toRoutes = $toStop->routePatterns->map(fn ($pattern): ?int => $pattern->route_id)->filter()->unique();
                $sharedRoutes = $fromRoutes->intersect($toRoutes);
                $sameRouteSet = $sharedRoutes->count() === $fromRoutes->count()
                    && $sharedRoutes->count() === $toRoutes->count();
                if ($toRoutes->isEmpty() || $sameRouteSet) {
                    continue;
                }
                $distance = Geo::distanceMeters(
                    $fromStop->location_point['lat'],
                    $fromStop->location_point['lng'],
                    $toStop->location_point['lat'],
                    $toStop->location_point['lng'],
                );
                if ($distance > $radiusMeters) {
                    continue;
                }

                $candidates->push([
                    'from_stop' => $this->stopSummary($fromStop),
                    'to_stop' => $this->stopSummary($toStop),
                    'distance_meters' => round($distance, 2),
                    'routes' => $this->routeSummaries($fromStop, $toStop),
                    'patterns' => $this->patternSummaries($fromStop, $toStop),
                    'trust' => $this->trust($fromStop, $toStop),
                    'reason' => 'Different active route services have mapped stops within the bounded straight-line screening radius.',
                    'review_required' => true,
                ]);
            }
        }

        return $candidates
            ->sortBy(fn (array $candidate): string => sprintf('%010.2f:%010d:%010d', $candidate['distance_meters'], $candidate['from_stop']['id'], $candidate['to_stop']['id']))
            ->values();
    }

    /** @return array<string, mixed> */
    private function stopSummary(Stop $stop): array
    {
        return [
            'id' => $stop->getKey(),
            'name' => $stop->name,
            'code' => $stop->code,
            'verification' => Trust::status($stop),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function routeSummaries(Stop $fromStop, Stop $toStop): array
    {
        return $fromStop->routePatterns
            ->merge($toStop->routePatterns)
            ->filter(fn ($pattern): bool => $pattern->route !== null)
            ->map(fn ($pattern): array => [
                'id' => $pattern->route->getKey(),
                'code' => $pattern->route->code,
                'name' => $pattern->route->name,
            ])
            ->unique('id')
            ->sortBy('code')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function patternSummaries(Stop $fromStop, Stop $toStop): array
    {
        return $fromStop->routePatterns
            ->merge($toStop->routePatterns)
            ->map(fn ($pattern): array => [
                'id' => $pattern->getKey(),
                'name' => $pattern->name,
                'direction' => $pattern->direction,
                'route_code' => $pattern->route?->code,
            ])
            ->unique('id')
            ->sortBy(fn (array $pattern): string => ($pattern['route_code'] ?? '').':'.$pattern['direction'].':'.$pattern['id'])
            ->values()
            ->all();
    }

    private function trust(Stop $fromStop, Stop $toStop): JourneyTrustState
    {
        $models = [$fromStop, $toStop, ...$fromStop->routePatterns->all(), ...$toStop->routePatterns->all()];
        $states = collect($models)
            ->map(function ($model): ?string {
                $state = $model->verification_state ?? null;

                return $state instanceof VerificationState ? $state->value : $state;
            })
            ->filter()
            ->values();

        if ($states->isNotEmpty() && $states->every(fn (string $state): bool => $state === 'VERIFIED')) {
            return JourneyTrustState::VERIFIED;
        }
        if ($states->contains('VERIFIED')) {
            return JourneyTrustState::MIXED;
        }

        return JourneyTrustState::UNVERIFIED;
    }
}
