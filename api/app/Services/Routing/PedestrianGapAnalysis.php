<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PedestrianGapAnalysis
{
    /** @return array<string, mixed> */
    public function analyze(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! Schema::hasTable('pedestrian_network_datasets')) {
            throw new RuntimeException('Pedestrian gap analysis requires the real PostgreSQL/PostGIS graph.');
        }
        $dataset = DB::table('pedestrian_network_datasets')->where('is_active', true)->first();
        if ($dataset === null) {
            throw new RuntimeException('No active pedestrian dataset is installed.');
        }
        $bounds = [
            'min_latitude' => (float) $dataset->min_latitude,
            'min_longitude' => (float) $dataset->min_longitude,
            'max_latitude' => (float) $dataset->max_latitude,
            'max_longitude' => (float) $dataset->max_longitude,
        ];

        $stops = $this->entities('stops', $dataset->id, $bounds);
        $places = $this->entities('places', $dataset->id, $bounds);
        $classifications = collect([...$stops, ...$places])->groupBy('classification')->map->count()->all();
        $clusters = collect([...$stops, ...$places])
            ->filter(fn (array $item): bool => $item['classification'] !== 'CONNECTABLE')
            ->groupBy(fn (array $item): string => sprintf('%.3f,%.3f', (float) ($item['latitude'] ?? 0), (float) ($item['longitude'] ?? 0)))
            ->map(function ($items, string $key): array {
                return [
                    'cluster' => $key,
                    'count' => $items->count(),
                    'entities' => $items->take(12)->map(fn (array $item): array => ['type' => $item['entity_type'], 'stable_id' => $item['stable_id'], 'name' => $item['name']])->values()->all(),
                    'classification' => $items->groupBy('classification')->map->count()->all(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'dataset' => [
                'stable_id' => $dataset->stable_id,
                'source' => $dataset->source,
                'source_url' => $dataset->source_url,
                'source_timestamp' => $dataset->source_timestamp,
                'bounds' => $bounds,
                'nodes' => (int) $dataset->node_count,
                'edges' => (int) $dataset->edge_count,
            ],
            'policy' => [
                'snap_threshold_meters' => 25,
                'coordinates_moved' => 0,
                'graph_mutated' => false,
            ],
            'stops' => $this->summary($stops),
            'places' => $this->summary($places),
            'clusters' => $clusters,
            'systemic_clusters' => array_values(array_filter($clusters, static fn (array $cluster): bool => $cluster['count'] >= 2)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function entities(string $table, int $datasetId, array $bounds): array
    {
        $entities = DB::select(
            "SELECT e.id, e.stable_id, e.name,
                    ST_Y(e.location::geometry) AS latitude,
                    ST_X(e.location::geometry) AS longitude,
                    nearest.distance_meters
             FROM {$table} e
             LEFT JOIN LATERAL (
                 SELECT ST_Distance(e.location, n.location) AS distance_meters
                 FROM pedestrian_network_nodes n
                 WHERE n.dataset_id = ? AND n.is_walkable = true
                 ORDER BY n.location <-> e.location
                 LIMIT 1
             ) nearest ON true
             WHERE e.is_active = true AND e.is_demo = false
             ORDER BY e.id",
            [$datasetId],
        );

        return array_map(function (object $entity) use ($bounds, $table): array {
            $latitude = $entity->latitude === null ? null : (float) $entity->latitude;
            $longitude = $entity->longitude === null ? null : (float) $entity->longitude;
            $inside = $latitude !== null && $longitude !== null
                && $latitude >= $bounds['min_latitude'] && $latitude <= $bounds['max_latitude']
                && $longitude >= $bounds['min_longitude'] && $longitude <= $bounds['max_longitude'];
            $distance = $entity->distance_meters === null ? null : (float) $entity->distance_meters;
            $classification = match (true) {
                ! $inside => 'OUTSIDE_NEW_GRAPH',
                $distance === null => 'MISSING_OSM_WAY',
                $distance > 25 => 'ACCESS_UNCERTAIN',
                default => 'CONNECTABLE',
            };

            return [
                'entity_type' => $table === 'stops' ? 'STOP' : 'PLACE',
                'stable_id' => (string) $entity->stable_id,
                'name' => (string) $entity->name,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'nearest_distance_meters' => $distance === null ? null : round($distance, 2),
                'classification' => $classification,
            ];
        }, $entities);
    }

    /** @param list<array<string, mixed>> $entities @return array<string, mixed> */
    private function summary(array $entities): array
    {
        return [
            'total' => count($entities),
            'connectable' => count(array_filter($entities, static fn (array $item): bool => $item['classification'] === 'CONNECTABLE')),
            'unconnectable' => count(array_filter($entities, static fn (array $item): bool => $item['classification'] !== 'CONNECTABLE')),
            'classifications' => collect($entities)->groupBy('classification')->map->count()->all(),
            'items' => array_values(array_filter($entities, static fn (array $item): bool => $item['classification'] !== 'CONNECTABLE')),
        ];
    }
}
