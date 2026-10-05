<?php

namespace App\Services\Data;

use App\Models\City;
use App\Models\Fare;
use App\Models\Operator;
use App\Models\Place;
use App\Models\PlaceAlias;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Models\TransferRelationship;
use App\Models\VehicleType;
use App\Support\Geo;
use App\Support\SearchText;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatasetImporter
{
    /** @param array<string, mixed> $data */
    public function import(array $data): ImportSummary
    {
        $summary = new ImportSummary;

        return DB::transaction(function () use ($data, $summary): ImportSummary {
            $sourceIds = [];
            foreach ($this->sorted($data['sources'] ?? []) as $record) {
                $source = $this->saveRecord(
                    ProvenanceSource::class,
                    $record['stable_id'],
                    [
                        'source_type' => $record['source_type'],
                        'title' => $record['title'],
                        'publisher' => $record['publisher'] ?? null,
                        'url' => $record['url'] ?? null,
                        'accessed_at' => $record['accessed_at'] ?? null,
                        'checked_at' => $record['checked_at'] ?? null,
                        'effective_from' => $record['effective_from'] ?? null,
                        'effective_until' => $record['effective_until'] ?? null,
                        'license' => $record['license'] ?? null,
                        'verification_method' => $record['verification_method'] ?? null,
                        'verified_by' => $record['verified_by'] ?? null,
                        'notes' => $record['notes'] ?? null,
                    ],
                    'sources',
                    fn (): ?Model => ProvenanceSource::query()->where('title', $record['title'])->first(),
                    $summary,
                );
                $sourceIds[$record['stable_id']] = $source->id;
            }

            $cityIds = [];
            foreach ($this->sorted($data['cities'] ?? []) as $record) {
                $city = $this->saveRecord(
                    City::class,
                    $record['stable_id'],
                    [
                        'name' => $record['name'],
                        'slug' => $record['slug'],
                        'country_code' => $record['country_code'] ?? 'PH',
                        'is_active' => $record['is_active'] ?? true,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'notes' => $record['notes'] ?? null,
                    ],
                    'cities',
                    fn (): ?Model => City::query()->where('slug', $record['slug'])->first(),
                    $summary,
                );
                $cityIds[$record['stable_id']] = $city->id;
            }

            $placeIds = [];
            foreach ($this->sorted($data['places'] ?? []) as $record) {
                $cityId = $this->reference($cityIds, $record['city_id'], 'city');
                $place = $this->saveRecord(
                    Place::class,
                    $record['stable_id'],
                    [
                        'city_id' => $cityId,
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'name' => $record['name'],
                        'slug' => $record['slug'],
                        'category' => strtoupper($record['category']),
                        'description' => $record['description'] ?? null,
                        'location' => $record['location'],
                        'is_active' => $record['is_active'] ?? true,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                        'notes' => $record['notes'] ?? null,
                    ],
                    'places',
                    fn (): ?Model => Place::query()->where('city_id', $cityId)->where('slug', $record['slug'])->first(),
                    $summary,
                );
                $placeIds[$record['stable_id']] = $place->id;
            }

            foreach ($this->sorted($data['aliases'] ?? []) as $record) {
                $placeId = $this->reference($placeIds, $record['place_id'], 'place');
                $normalizedAlias = SearchText::normalize($record['alias']);
                $this->saveRecord(
                    PlaceAlias::class,
                    $record['stable_id'],
                    [
                        'place_id' => $placeId,
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'alias' => $record['alias'],
                        'normalized_alias' => $normalizedAlias,
                        'is_active' => $record['is_active'] ?? true,
                    ],
                    'aliases',
                    fn (): ?Model => PlaceAlias::query()->where('place_id', $placeId)->where('normalized_alias', $normalizedAlias)->first(),
                    $summary,
                );
            }

            $operatorIds = [];
            foreach ($this->sorted($data['operators'] ?? []) as $record) {
                $operator = $this->saveRecord(
                    Operator::class,
                    $record['stable_id'],
                    [
                        'name' => $record['name'],
                        'slug' => $record['slug'],
                        'description' => $record['description'] ?? null,
                        'is_active' => $record['is_active'] ?? true,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                    ],
                    'operators',
                    fn (): ?Model => Operator::query()->where('slug', $record['slug'])->first(),
                    $summary,
                );
                $operatorIds[$record['stable_id']] = $operator->id;
            }

            $stopIds = [];
            foreach ($this->sorted($data['stops'] ?? []) as $record) {
                $cityId = $this->reference($cityIds, $record['city_id'], 'city');
                $nearbyPlaceId = ($record['nearby_place_id'] ?? null) === null
                    ? null
                    : $this->reference($placeIds, $record['nearby_place_id'], 'place');
                $stop = $this->saveRecord(
                    Stop::class,
                    $record['stable_id'],
                    [
                        'city_id' => $cityId,
                        'nearby_place_id' => $nearbyPlaceId,
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'name' => $record['name'],
                        'code' => $record['code'],
                        'category' => strtoupper($record['category'] ?? 'GENERAL'),
                        'is_boarding_point' => $record['is_boarding_point'] ?? true,
                        'boarding_notes' => $record['boarding_notes'] ?? null,
                        'boarding_side' => $record['boarding_side'] ?? null,
                        'direction_note' => $record['direction_note'] ?? null,
                        'location' => $record['location'],
                        'is_active' => $record['is_active'] ?? true,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                        'notes' => $record['notes'] ?? null,
                    ],
                    'stops',
                    fn (): ?Model => Stop::query()->where('city_id', $cityId)->where('code', $record['code'])->first(),
                    $summary,
                );
                $stopIds[$record['stable_id']] = $stop->id;
            }

            $vehicleTypeIds = VehicleType::query()->pluck('id', 'code')->all();
            $routeIds = [];
            foreach ($this->sorted($data['routes'] ?? []) as $record) {
                $cityId = $this->reference($cityIds, $record['city_id'], 'city');
                $vehicleTypeId = $vehicleTypeIds[$record['vehicle_type']] ?? null;
                if ($vehicleTypeId === null) {
                    throw new RuntimeException('Vehicle type '.$record['vehicle_type'].' is not seeded.');
                }
                $operatorId = $record['operator_id'] === null
                    ? null
                    : $this->reference($operatorIds, $record['operator_id'], 'operator');
                $route = $this->saveRecord(
                    Route::class,
                    $record['stable_id'],
                    [
                        'city_id' => $cityId,
                        'operator_id' => $operatorId,
                        'vehicle_type_id' => $vehicleTypeId,
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'code' => $record['code'],
                        'name' => $record['name'],
                        'description' => $record['description'] ?? null,
                        'is_active' => $record['is_active'] ?? true,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                        'notes' => $record['notes'] ?? null,
                    ],
                    'routes',
                    fn (): ?Model => Route::query()->where('city_id', $cityId)->where('code', $record['code'])->first(),
                    $summary,
                );
                $routeIds[$record['stable_id']] = $route->id;
            }

            $patternIds = [];
            foreach ($this->sorted($data['patterns'] ?? []) as $record) {
                $routeId = $this->reference($routeIds, $record['route_id'], 'route');
                $pattern = $this->saveRecord(
                    RoutePattern::class,
                    $record['stable_id'],
                    [
                        'route_id' => $routeId,
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'name' => $record['name'],
                        'direction' => $record['direction'],
                        'service_notes' => $record['service_notes'] ?? null,
                        'geometry' => $record['geometry'],
                        'is_active' => $record['is_active'] ?? true,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                    ],
                    'patterns',
                    fn (): ?Model => RoutePattern::query()->where('route_id', $routeId)->where('name', $record['name'])->first(),
                    $summary,
                );
                $patternIds[$record['stable_id']] = $pattern->id;

                foreach ($this->sorted($record['stops'] ?? [], 'sequence') as $patternStop) {
                    $stopId = $this->reference($stopIds, $patternStop['stop_id'], 'stop');
                    $this->saveRecord(
                        RoutePatternStop::class,
                        $patternStop['stable_id'],
                        [
                            'route_pattern_id' => $pattern->id,
                            'stop_id' => $stopId,
                            'provenance_source_id' => $this->sourceId($patternStop['source_id'] ?? ($record['source_id'] ?? null), $sourceIds),
                            'sequence' => $patternStop['sequence'],
                            'boarding_allowed' => $patternStop['boarding_allowed'] ?? true,
                            'alighting_allowed' => $patternStop['alighting_allowed'] ?? true,
                            'verification_state' => $patternStop['verification_state'] ?? 'UNVERIFIED',
                            'instruction' => $patternStop['instruction'] ?? null,
                            'service_metadata' => $patternStop['service_metadata'] ?? null,
                        ],
                        'pattern_stops',
                        null,
                        $summary,
                    );
                }
            }

            foreach ($this->sorted($data['transfers'] ?? []) as $record) {
                $cityId = $this->reference($cityIds, $record['city_id'], 'city');
                $fromStopId = $this->reference($stopIds, $record['from_stop_id'], 'stop');
                $toStopId = $this->reference($stopIds, $record['to_stop_id'], 'stop');
                $this->saveRecord(
                    TransferRelationship::class,
                    $record['stable_id'],
                    [
                        'city_id' => $cityId,
                        'from_stop_id' => $fromStopId,
                        'to_stop_id' => $toStopId,
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'relationship_type' => $record['relationship_type'],
                        'distance_meters' => $record['distance_meters'] ?? null,
                        'from_route_pattern_id' => ($record['from_pattern_id'] ?? null) === null
                            ? null
                            : $this->reference($patternIds, $record['from_pattern_id'], 'pattern'),
                        'to_route_pattern_id' => ($record['to_pattern_id'] ?? null) === null
                            ? null
                            : $this->reference($patternIds, $record['to_pattern_id'], 'pattern'),
                        'landmark' => $record['landmark'] ?? null,
                        'practical_note' => $record['practical_note'] ?? null,
                        'is_active' => $record['is_active'] ?? true,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                        'notes' => $record['notes'] ?? null,
                    ],
                    'transfers',
                    fn (): ?Model => TransferRelationship::query()
                        ->where('from_stop_id', $fromStopId)
                        ->where('to_stop_id', $toStopId)
                        ->first(),
                    $summary,
                );
            }

            foreach ($this->sorted($data['fares'] ?? []) as $record) {
                $routeId = $this->reference($routeIds, $record['route_id'], 'route');
                $vehicleTypeId = $vehicleTypeIds[$record['vehicle_type']] ?? null;
                if ($vehicleTypeId === null) {
                    throw new RuntimeException('Vehicle type '.$record['vehicle_type'].' is not seeded.');
                }
                $routePatternId = ($record['pattern_id'] ?? null) === null
                    ? null
                    : $this->reference($patternIds, $record['pattern_id'], 'pattern');
                $originStopId = ($record['origin_stop_id'] ?? null) === null
                    ? null
                    : $this->reference($stopIds, $record['origin_stop_id'], 'stop');
                $destinationStopId = ($record['destination_stop_id'] ?? null) === null
                    ? null
                    : $this->reference($stopIds, $record['destination_stop_id'], 'stop');
                $this->saveRecord(
                    Fare::class,
                    $record['stable_id'],
                    [
                        'route_id' => $routeId,
                        'vehicle_type_id' => $vehicleTypeId,
                        'route_pattern_id' => $routePatternId,
                        'origin_stop_id' => $originStopId,
                        'destination_stop_id' => $destinationStopId,
                        'provenance_source_id' => $this->sourceId($record['source_id'] ?? null, $sourceIds),
                        'fare_class' => $record['fare_class'] ?? 'REGULAR',
                        'amount' => $record['amount'],
                        'currency' => $record['currency'] ?? 'PHP',
                        'min_distance_km' => $record['min_distance_km'] ?? null,
                        'max_distance_km' => $record['max_distance_km'] ?? null,
                        'effective_from' => $record['effective_from'],
                        'effective_until' => $record['effective_until'] ?? null,
                        'verification_state' => $record['verification_state'],
                        'is_demo' => $record['is_demo'],
                        'notes' => $record['notes'] ?? null,
                    ],
                    'fares',
                    fn (): ?Model => Fare::query()->where('route_id', $routeId)->where('fare_class', $record['fare_class'] ?? 'REGULAR')->where('effective_from', $record['effective_from'])->first(),
                    $summary,
                );
            }

            return $summary;
        });
    }

    /** @param array<int, array<string, mixed>> $records @return list<array<string, mixed>> */
    private function sorted(array $records, string $key = 'stable_id'): array
    {
        usort($records, function (array $left, array $right) use ($key): int {
            return strcmp((string) ($left[$key] ?? ''), (string) ($right[$key] ?? ''));
        });

        return $records;
    }

    /** @param class-string<Model> $modelClass @param array<string, mixed> $attributes */
    private function saveRecord(string $modelClass, string $stableId, array $attributes, string $type, ?Closure $fallback, ImportSummary $summary): Model
    {
        $model = $modelClass::query()->where('stable_id', $stableId)->first();
        if ($model === null && $fallback !== null) {
            $model = $fallback();
        }
        if ($model === null) {
            $model = new $modelClass;
        } elseif ($model->stable_id !== null && $model->stable_id !== $stableId) {
            throw new RuntimeException("{$type} natural key belongs to stable_id {$model->stable_id}, not {$stableId}.");
        }

        $isExisting = $model->exists;
        if ($isExisting && config('database.default') === 'pgsql') {
            $this->removeUnchangedSpatialAttributes($model, $attributes);
        }
        $model->stable_id = $stableId;
        $model->fill($attributes);
        $model->save();
        $summary->record($type, ! $isExisting ? 'imported' : ($model->wasChanged() ? 'updated' : 'skipped'));

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    private function removeUnchangedSpatialAttributes(Model $model, array &$attributes): void
    {
        if (array_key_exists('location', $attributes) && is_array($attributes['location'])) {
            $location = $attributes['location'];
            $current = DB::table($model->getTable())
                ->where($model->getKeyName(), $model->getKey())
                ->selectRaw('ST_X(location::geometry) AS longitude, ST_Y(location::geometry) AS latitude')
                ->first();
            if ($current !== null && abs((float) $current->latitude - (float) $location['lat']) < 0.00000001 && abs((float) $current->longitude - (float) $location['lng']) < 0.00000001) {
                unset($attributes['location']);
            }
        }

        if (array_key_exists('geometry', $attributes) && is_array($attributes['geometry'])) {
            $geometryWkt = Geo::multiLineStringWkt($attributes['geometry']);
            $geometryWkt = preg_replace('/^SRID=4326;/', '', $geometryWkt) ?? $geometryWkt;
            $current = DB::selectOne(
                'SELECT ST_Equals(geometry, ST_GeomFromText(?, 4326)) AS same FROM '.$model->getTable().' WHERE id = ?',
                [$geometryWkt, $model->getKey()],
            );
            if ($current !== null && (bool) $current->same) {
                unset($attributes['geometry']);
            }
        }
    }

    /** @param array<string, int> $ids */
    private function reference(array $ids, string $stableId, string $type): int
    {
        if (! isset($ids[$stableId])) {
            throw new RuntimeException("Unresolved {$type} reference {$stableId}.");
        }

        return $ids[$stableId];
    }

    /** @param array<string, int> $ids */
    private function sourceId(?string $stableId, array $ids): ?int
    {
        return $stableId === null ? null : $this->reference($ids, $stableId, 'source');
    }
}
