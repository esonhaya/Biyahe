<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PedestrianNetworkStatus extends Command
{
    protected $signature = 'biyahe:pedestrian:status {--json : Emit machine-readable status}';

    protected $description = 'Inspect the active bounded OSM pedestrian dataset and graph integrity.';

    public function handle(): int
    {
        $tables = ['pedestrian_network_datasets', 'pedestrian_network_nodes', 'pedestrian_network_edges'];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->error('Pedestrian graph schema is incomplete: '.$table);

                return self::FAILURE;
            }
        }
        if (! Schema::hasColumn('pedestrian_network_nodes', 'crossing')) {
            $this->error('Pedestrian graph schema is missing mapped crossing metadata.');

            return self::FAILURE;
        }

        $dataset = DB::table('pedestrian_network_datasets')->where('is_active', true)->first();
        if ($dataset === null) {
            $this->error('No active pedestrian dataset is installed.');

            return self::FAILURE;
        }

        $nodeQuery = DB::table('pedestrian_network_nodes')->where('dataset_id', $dataset->id);
        $edgeQuery = DB::table('pedestrian_network_edges')->where('dataset_id', $dataset->id);
        $configuredBounds = (array) config('biyahe.pedestrian.coverage', []);
        $nodes = (clone $nodeQuery)->count();
        $walkableNodes = (clone $nodeQuery)->where('is_walkable', true)->count();
        $restrictedNodes = (clone $nodeQuery)->where('is_walkable', false)->count();
        $edges = (clone $edgeQuery)->count();
        $brokenReferences = DB::table('pedestrian_network_edges as edges')
            ->leftJoin('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
            ->leftJoin('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
            ->where('edges.dataset_id', $dataset->id)
            ->where(fn ($query) => $query
                ->whereNull('from_nodes.id')
                ->orWhereNull('to_nodes.id')
                ->orWhereColumn('from_nodes.dataset_id', '!=', 'edges.dataset_id')
                ->orWhereColumn('to_nodes.dataset_id', '!=', 'edges.dataset_id'))
            ->count();
        $zeroLengthEdges = (clone $edgeQuery)->where('length_meters', '<=', 0)->count();
        $invalidCoordinates = (clone $nodeQuery)
            ->where(fn ($query) => $query
                ->where('latitude', '<', -90)->orWhere('latitude', '>', 90)
                ->orWhere('longitude', '<', -180)->orWhere('longitude', '>', 180))
            ->count();
        $outsideScope = (clone $nodeQuery)
            ->where(fn ($query) => $query
                ->where('latitude', '<', (float) $configuredBounds['min_latitude'])
                ->orWhere('latitude', '>', (float) $configuredBounds['max_latitude'])
                ->orWhere('longitude', '<', (float) $configuredBounds['min_longitude'])
                ->orWhere('longitude', '>', (float) $configuredBounds['max_longitude']))
            ->count();
        $invalidGeometry = null;
        $invalidSpatialReference = null;
        if (DB::connection()->getDriverName() === 'pgsql') {
            $invalidGeometry = (clone $edgeQuery)->whereRaw("geometry IS NULL OR ST_GeometryType(geometry) <> 'ST_LineString' OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry) OR ST_Length(geometry::geography) <= 0")->count();
            $invalidSpatialReference = (clone $edgeQuery)->whereRaw('ST_SRID(geometry) <> 4326')->count();
            $invalidCoordinates += (clone $nodeQuery)->whereRaw("location IS NULL OR ST_SRID(location::geometry) <> 4326 OR ST_GeometryType(location::geometry) <> 'ST_Point' OR ST_IsEmpty(location::geometry) OR NOT ST_IsValid(location::geometry)")->count();
        }

        $componentStats = $this->componentStats((int) $dataset->id, $walkableNodes);
        $metadata = json_decode((string) $dataset->metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];
        $sourceDetails = is_array($metadata['source_details'] ?? null) ? $metadata['source_details'] : [];
        $sourceBounds = $sourceDetails['extract_bounds'] ?? null;
        $sourceChecksum = $sourceDetails['source_checksum_md5'] ?? null;
        $sourceTimestamp = $sourceDetails['source_timestamp'] ?? null;
        $sourceScheme = strtolower((string) parse_url((string) $dataset->source_url, PHP_URL_SCHEME));
        $validSourceMetadata = ($metadata['format'] ?? null) === 'osmium-osm-xml-v1'
            && ($sourceDetails['source'] ?? null) === 'OPENSTREETMAP'
            && ($sourceDetails['source_url'] ?? null) === $dataset->source_url
            && filter_var($dataset->source_url, FILTER_VALIDATE_URL) !== false
            && in_array($sourceScheme, ['http', 'https'], true)
            && ($sourceDetails['license'] ?? null) === 'ODbL-1.0'
            && ($sourceDetails['attribution'] ?? null) === '© OpenStreetMap contributors'
            && is_string($sourceChecksum) && preg_match('/^[a-f0-9]{32}$/i', $sourceChecksum) === 1
            && is_string($sourceTimestamp) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sourceTimestamp) === 1
            && is_string($sourceDetails['source_file'] ?? null) && $sourceDetails['source_file'] !== ''
            && is_int($sourceDetails['source_elements'] ?? null)
            && is_string($sourceDetails['osmium_version'] ?? null) && preg_match('/^\d+\.\d+(\.\d+)?$/', $sourceDetails['osmium_version']) === 1
            && in_array(($sourceDetails['extract_strategy'] ?? null), ['complete_ways', 'streaming_sqlite_ref_complete', 'offline_scope_first_sqlite_v3'], true)
            && (! in_array(($sourceDetails['extract_strategy'] ?? null), ['streaming_sqlite_ref_complete', 'offline_scope_first_sqlite_v3'], true)
                || (is_string($sourceDetails['source_checksum_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $sourceDetails['source_checksum_sha256']) === 1))
            && is_array($sourceBounds) && $sourceBounds === config('biyahe.pedestrian.coverage');
        $countMismatch = (int) $dataset->node_count !== $nodes || (int) $dataset->edge_count !== $edges;
        $invalidProvenance = $dataset->source !== 'OPENSTREETMAP'
            || $dataset->license !== 'ODbL-1.0'
            || $dataset->attribution !== '© OpenStreetMap contributors'
            || filter_var($dataset->source_url, FILTER_VALIDATE_URL) === false
            || ! preg_match('/^[a-f0-9]{64}$/i', (string) $dataset->content_hash)
            || ! $validSourceMetadata;
        $validation = $nodes > 0 && $edges > 0 && ! $countMismatch && ! $invalidProvenance
            && $brokenReferences === 0 && $zeroLengthEdges === 0 && $invalidCoordinates === 0 && $outsideScope === 0
            && ($invalidGeometry === null || $invalidGeometry === 0)
            && ($invalidSpatialReference === null || $invalidSpatialReference === 0);
        $status = [
            'validation' => $validation ? 'PASS' : 'FAIL',
            'active_dataset' => [
                'stable_id' => $dataset->stable_id,
                'source' => $dataset->source,
                'source_url' => $dataset->source_url,
                'license' => $dataset->license,
                'attribution' => $dataset->attribution,
                'source_timestamp' => $dataset->source_timestamp,
                'imported_at' => $dataset->imported_at,
                'bounds' => [
                    'min_latitude' => (float) $dataset->min_latitude,
                    'min_longitude' => (float) $dataset->min_longitude,
                    'max_latitude' => (float) $dataset->max_latitude,
                    'max_longitude' => (float) $dataset->max_longitude,
                ],
                'stored_counts' => ['nodes' => (int) $dataset->node_count, 'edges' => (int) $dataset->edge_count],
                'actual_counts' => ['nodes' => $nodes, 'edges' => $edges],
                'walkable_nodes' => $walkableNodes,
                'restricted_nodes' => $restrictedNodes,
                'content_hash' => $dataset->content_hash,
                'import_metadata' => $metadata,
            ],
            'graph_validation' => [
                'count_mismatch' => $countMismatch,
                'invalid_provenance' => $invalidProvenance,
                'broken_references' => $brokenReferences,
                'zero_length_edges' => $zeroLengthEdges,
                'invalid_coordinates' => $invalidCoordinates,
                'outside_scope_nodes' => $outsideScope,
                'invalid_geometries' => $invalidGeometry,
                'invalid_srids' => $invalidSpatialReference,
            ],
            'weak_connectivity' => $componentStats,
            'last_import_result' => [
                'status' => $validation ? 'ACTIVE_AND_VALID' : 'ACTIVE_BUT_INVALID',
                'dataset_stable_id' => $dataset->stable_id,
                'imported_at' => $dataset->imported_at,
                'metrics' => is_array($metadata['import_metrics'] ?? null) ? $metadata['import_metrics'] : null,
            ],
            'postgis' => DB::connection()->getDriverName() === 'pgsql',
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('active_dataset: '.$dataset->stable_id);
            $this->line('source: '.$dataset->source.' '.$dataset->source_url);
            $this->line('source_timestamp: '.($dataset->source_timestamp ?? 'not supplied'));
            $this->line('imported_at: '.$dataset->imported_at);
            $this->line('license: '.$dataset->license);
            $this->line('attribution: '.$dataset->attribution);
            $this->line(sprintf('bounds: %.7F,%.7F,%.7F,%.7F', $dataset->min_latitude, $dataset->min_longitude, $dataset->max_latitude, $dataset->max_longitude));
            $this->line("nodes: {$nodes} stored=".(int) $dataset->node_count);
            $this->line('walkable_nodes: '.$walkableNodes);
            $this->line("edges: {$edges} stored=".(int) $dataset->edge_count);
            $this->line('connected_components: '.$componentStats['components']);
            $this->line('largest_component_nodes: '.$componentStats['largest_component_nodes']);
            $this->line('largest_component_percent: '.$componentStats['largest_component_percent']);
            $this->line('isolated_nodes: '.$componentStats['isolated_nodes']);
            $this->line('restricted_nodes: '.$restrictedNodes);
            $this->line('last_import_result: '.($validation ? 'ACTIVE_AND_VALID' : 'ACTIVE_BUT_INVALID'));
            $this->line('graph_validation: '.($validation ? 'PASS' : 'FAIL'));
        }

        return $validation ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{components: int, largest_component_nodes: int, largest_component_percent: float, isolated_nodes: int} */
    private function componentStats(int $datasetId, int $nodeCount): array
    {
        $parents = DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId)->where('is_walkable', true)->pluck('id')->mapWithKeys(
            static fn ($id): array => [(int) $id => (int) $id],
        )->all();
        $sizes = array_fill_keys(array_keys($parents), 1);
        $degrees = array_fill_keys(array_keys($parents), 0);
        $components = count($parents);

        foreach (DB::table('pedestrian_network_edges')->where('dataset_id', $datasetId)->select(['from_node_id', 'to_node_id'])->cursor() as $edge) {
            $from = (int) $edge->from_node_id;
            $to = (int) $edge->to_node_id;
            if (! isset($parents[$from], $parents[$to])) {
                continue;
            }
            $degrees[$from]++;
            $degrees[$to]++;
            $fromRoot = $this->root($parents, $from);
            $toRoot = $this->root($parents, $to);
            if ($fromRoot === $toRoot) {
                continue;
            }
            if ($sizes[$fromRoot] < $sizes[$toRoot]) {
                [$fromRoot, $toRoot] = [$toRoot, $fromRoot];
            }
            $parents[$toRoot] = $fromRoot;
            $sizes[$fromRoot] += $sizes[$toRoot];
            $components--;
        }

        $largest = $nodeCount === 0 ? 0 : max($sizes);

        return [
            'components' => $components,
            'largest_component_nodes' => $largest,
            'largest_component_percent' => $nodeCount === 0 ? 0.0 : round($largest / $nodeCount * 100, 2),
            'isolated_nodes' => count(array_filter($degrees, static fn (int $degree): bool => $degree === 0)),
        ];
    }

    /** @param array<int, int> $parents */
    private function root(array &$parents, int $node): int
    {
        $root = $node;
        while ($parents[$root] !== $root) {
            $root = $parents[$root];
        }
        while ($parents[$node] !== $node) {
            $next = $parents[$node];
            $parents[$node] = $root;
            $node = $next;
        }

        return $root;
    }
}
