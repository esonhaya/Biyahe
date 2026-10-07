<?php

namespace App\Services\Disruptions;

use App\Enums\DisruptionStatus;
use App\Models\Disruption;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ActiveDisruptionService
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function effectiveStatus(Disruption $disruption, ?CarbonImmutable $now = null): DisruptionStatus
    {
        $now ??= $this->now();

        if (in_array($disruption->status, [DisruptionStatus::DRAFT, DisruptionStatus::RESOLVED, DisruptionStatus::REJECTED, DisruptionStatus::EXPIRED], true)) {
            return $disruption->status;
        }
        if ($disruption->ends_at !== null && $disruption->ends_at->lessThanOrEqualTo($now)) {
            return DisruptionStatus::EXPIRED;
        }
        if ($disruption->starts_at !== null && $disruption->starts_at->isFuture()) {
            return DisruptionStatus::SCHEDULED;
        }

        return DisruptionStatus::ACTIVE;
    }

    public function isActive(Disruption $disruption, ?CarbonImmutable $now = null): bool
    {
        $now ??= $this->now();

        return in_array($disruption->status, [DisruptionStatus::ACTIVE, DisruptionStatus::SCHEDULED], true)
            && (app()->environment('testing') || ! $disruption->is_synthetic)
            && ($disruption->starts_at === null || $disruption->starts_at->lessThanOrEqualTo($now))
            && ($disruption->ends_at === null || $disruption->ends_at->greaterThan($now))
            && $disruption->provenance_source_id !== null;
    }

    /**
     * Get one bounded, eager-loaded advisory set.  Journey matching uses this
     * collection so a planner run does not issue a query for every leg.
     *
     * @return Collection<int, Disruption>
     */
    public function active(?CarbonImmutable $now = null, array $filters = []): Collection
    {
        $now ??= $this->now();
        $query = $this->baseQuery()
            ->whereIn('disruptions.status', [DisruptionStatus::ACTIVE->value, DisruptionStatus::SCHEDULED->value])
            ->where(function (Builder $builder) use ($now): void {
                $builder->whereNull('disruptions.starts_at')->orWhere('disruptions.starts_at', '<=', $now);
            })
            ->where(function (Builder $builder) use ($now): void {
                $builder->whereNull('disruptions.ends_at')->orWhere('disruptions.ends_at', '>', $now);
            })
            ->whereNotNull('disruptions.provenance_source_id')
            ->when(! app()->environment('testing'), fn (Builder $builder) => $builder->where('disruptions.is_synthetic', false))
            ->orderByDesc('disruptions.severity')
            ->orderBy('disruptions.starts_at');

        if (isset($filters['route_id'])) {
            $query->whereHas('routes', fn (Builder $builder) => $builder->whereKey((int) $filters['route_id']));
        }
        if (isset($filters['route_pattern_id'])) {
            $query->whereHas('patterns', fn (Builder $builder) => $builder->whereKey((int) $filters['route_pattern_id']));
        }
        if (isset($filters['stop_id'])) {
            $query->whereHas('stops', fn (Builder $builder) => $builder->whereKey((int) $filters['stop_id']));
        }
        if (isset($filters['place_id'])) {
            $query->whereHas('places', fn (Builder $builder) => $builder->whereKey((int) $filters['place_id']));
        }
        if (config('database.default') === 'pgsql' && collect(['south', 'west', 'north', 'east'])->every(fn (string $key): bool => isset($filters[$key]))) {
            $query->where(function (Builder $builder) use ($filters): void {
                $builder->whereNull('disruptions.geometry')->orWhereRaw(
                    'ST_Intersects(disruptions.geometry, ST_MakeEnvelope(?, ?, ?, ?, 4326))',
                    [(float) $filters['west'], (float) $filters['south'], (float) $filters['east'], (float) $filters['north']],
                );
            });
        }

        return $query->limit((int) config('biyahe.disruptions.public_limit', 50))->get();
    }

    /** @return Builder<Disruption> */
    public function baseQuery(): Builder
    {
        $query = Disruption::query()
            ->with([
                'provenanceSource',
                'routes',
                'patterns.route',
                'stops',
                'places',
                'transfers',
            ]);

        if (config('database.default') === 'pgsql') {
            $query->addSelect([
                'disruptions.*',
                DB::raw('ST_AsGeoJSON(disruptions.geometry) AS geometry_geojson'),
            ]);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function publicSummary(Disruption $disruption, ?CarbonImmutable $now = null): array
    {
        $now ??= $this->now();
        $status = $this->effectiveStatus($disruption, $now);

        return [
            'id' => $disruption->getKey(),
            'stable_id' => $disruption->stable_id,
            'category' => $disruption->category?->value ?? $disruption->category,
            'headline' => $disruption->headline,
            'description' => $disruption->description,
            'severity' => $disruption->severity?->value ?? $disruption->severity,
            'status' => $status->value,
            'starts_at' => $disruption->starts_at?->toIso8601String(),
            'ends_at' => $disruption->ends_at?->toIso8601String(),
            'source_class' => $disruption->source_class?->value ?? $disruption->source_class,
            'source' => $this->sourceSummary($disruption),
            'checked_at' => $disruption->provenanceSource?->checked_at?->toIso8601String(),
            'geometry' => $disruption->geometry_geo_json,
            'affected_entities' => [
                'routes' => $disruption->routes->map(fn ($route): array => [
                    'id' => $route->getKey(),
                    'code' => $route->code,
                    'name' => $route->name,
                ])->values()->all(),
                'patterns' => $disruption->patterns->map(fn ($pattern): array => [
                    'id' => $pattern->getKey(),
                    'name' => $pattern->name,
                    'direction' => $pattern->direction,
                    'route_code' => $pattern->relationLoaded('route') ? $pattern->route?->code : null,
                ])->values()->all(),
                'stops' => $disruption->stops->map(fn ($stop): array => [
                    'id' => $stop->getKey(),
                    'name' => $stop->name,
                    'location' => $stop->location_point,
                ])->values()->all(),
                'places' => $disruption->places->map(fn ($place): array => [
                    'id' => $place->getKey(),
                    'name' => $place->name,
                    'location' => $place->location_point,
                ])->values()->all(),
                'transfers' => $disruption->transfers->map(fn ($transfer): array => [
                    'id' => $transfer->getKey(),
                    'stable_id' => $transfer->stable_id,
                    'from_stop_id' => $transfer->from_stop_id,
                    'to_stop_id' => $transfer->to_stop_id,
                ])->values()->all(),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function sourceSummary(Disruption $disruption): ?array
    {
        $source = $disruption->provenanceSource;
        if ($source === null) {
            return null;
        }

        return [
            'type' => $source->source_type,
            'title' => $source->title,
            'publisher' => $source->publisher,
            'url' => $source->url,
            'checked_at' => $source->checked_at?->toIso8601String(),
            'observed_at' => $source->observed_at?->toIso8601String(),
            'attribution' => $source->publisher ?? $source->title,
            'conflict_status' => $source->conflict_status?->value ?? $source->conflict_status,
        ];
    }
}
