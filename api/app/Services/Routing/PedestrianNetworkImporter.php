<?php

namespace App\Services\Routing;

use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class PedestrianNetworkImporter
{
    private const ATTRIBUTION = '© OpenStreetMap contributors';

    private const LICENSE = 'ODbL-1.0';

    public function import(string $path, string $sourceUrl, bool $mergeActive = false): PedestrianNetworkImportResult
    {
        $startedAt = hrtime(true);
        $maximumBytes = max(1024, (int) config('biyahe.pedestrian.maximum_input_bytes', 20_000_000));
        if (! is_file($path) || ! is_readable($path) || filesize($path) === false || filesize($path) > $maximumBytes) {
            throw new InvalidArgumentException('Input must be a readable JSON file within the configured byte limit.');
        }
        if (! $this->validSourceUrl($sourceUrl)) {
            throw new InvalidArgumentException('Source URL must be a valid HTTP or HTTPS URL.');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || strlen($contents) > $maximumBytes) {
            throw new InvalidArgumentException('Input must be a readable JSON file within the configured byte limit.');
        }
        try {
            $document = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Input is not valid bounded Overpass JSON.');
        }
        if (! is_array($document) || ! is_array($document['elements'] ?? null)) {
            throw new InvalidArgumentException('Input must contain an Overpass elements array.');
        }

        $sourceMetadata = $this->sourceMetadata($document, $sourceUrl);
        [$nodes, $ways, $parseMetrics] = $this->parseElements($document['elements']);
        [$selectedNodes, $edges, $acceptedWays, $restrictedWays] = $this->buildGraph($nodes, $ways);
        if ($selectedNodes === [] || $edges === []) {
            throw new InvalidArgumentException('Input contains no usable pedestrian network inside the configured Cebu scope.');
        }

        $mergeMetadata = [];
        if ($mergeActive) {
            [$selectedNodes, $edges, $mergeMetadata] = $this->mergeActiveGraph($selectedNodes, $edges, $sourceMetadata);
        }

        $candidateElements = count($document['elements']);
        $rejectedElements = max(0, $candidateElements - count($selectedNodes) - $acceptedWays);
        $restrictedElements = $parseMetrics['restricted_nodes'] + $restrictedWays;
        $sourceElements = $sourceMetadata['source_elements'] ?? $candidateElements;
        $contentHash = hash('sha256', $contents.'|'.json_encode($mergeMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $stableId = 'osm-cebu-walk-'.substr($contentHash, 0, 32);
        $sourceTimestamp = $document['osm3s']['timestamp_osm_base'] ?? null;
        $sourceTimestamp ??= $sourceMetadata['source_timestamp'] ?? null;
        $sourceTimestamp = is_string($sourceTimestamp) ? $this->validTimestamp($sourceTimestamp) : null;
        $bounds = $this->bounds($selectedNodes);
        $importMetrics = [
            'source_elements' => $sourceElements,
            'candidate_elements' => $candidateElements,
            'candidate_nodes' => $parseMetrics['input_nodes'],
            'candidate_ways' => $parseMetrics['input_ways'],
            'imported_nodes' => count($selectedNodes),
            'imported_edges' => count($edges),
            'rejected_elements' => $rejectedElements,
            'restricted_elements' => $restrictedElements,
            'restriction_relations_observed' => $parseMetrics['restriction_relations'],
            'vehicle_only_turn_restrictions' => $parseMetrics['vehicle_only_restrictions'],
            'pedestrian_exempt_turn_restrictions' => $parseMetrics['pedestrian_exempt_restrictions'],
            ...$mergeMetadata,
        ];
        $datasetMetadata = [
            'format' => $sourceMetadata['format'] ?? 'overpass-json',
            'extract_scope' => config('biyahe.pedestrian.coverage'),
            'importer' => 'local-pedestrian-network-v2',
            'source_details' => $sourceMetadata,
            'import_metrics' => $importMetrics,
            'merge_active_graph' => $mergeActive,
        ];

        return DB::transaction(function () use ($startedAt, $stableId, $sourceUrl, $sourceTimestamp, $contentHash, $bounds, $selectedNodes, $edges, $datasetMetadata, $importMetrics): PedestrianNetworkImportResult {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['biyahe:pedestrian-network-import']);
            }

            $existing = DB::table('pedestrian_network_datasets')->where('content_hash', $contentHash)->first();
            if ($existing !== null) {
                $this->validateDatasetGraph((int) $existing->id, (int) $existing->node_count, (int) $existing->edge_count);
                DB::table('pedestrian_network_datasets')->update(['is_active' => false, 'updated_at' => now()]);
                DB::table('pedestrian_network_datasets')->where('id', $existing->id)->update(['is_active' => true, 'updated_at' => now()]);
                $existingMetadata = json_decode((string) $existing->metadata, true);
                $existingMetrics = is_array($existingMetadata) && is_array($existingMetadata['import_metrics'] ?? null)
                    ? $existingMetadata['import_metrics']
                    : $importMetrics;

                return new PedestrianNetworkImportResult(
                    $existing->stable_id,
                    (int) $existing->node_count,
                    (int) $existing->edge_count,
                    true,
                    (int) ($existingMetrics['source_elements'] ?? 0),
                    (int) ($existingMetrics['candidate_elements'] ?? 0),
                    (int) ($existingMetrics['rejected_elements'] ?? 0),
                    (int) ($existingMetrics['restricted_elements'] ?? 0),
                    round((hrtime(true) - $startedAt) / 1_000_000, 2),
                );
            }

            $datasetId = DB::table('pedestrian_network_datasets')->insertGetId([
                'stable_id' => $stableId,
                'source' => 'OPENSTREETMAP',
                'source_url' => $sourceUrl,
                'license' => self::LICENSE,
                'attribution' => self::ATTRIBUTION,
                'source_timestamp' => $sourceTimestamp,
                'imported_at' => now(),
                'min_latitude' => $bounds['min_latitude'],
                'min_longitude' => $bounds['min_longitude'],
                'max_latitude' => $bounds['max_latitude'],
                'max_longitude' => $bounds['max_longitude'],
                'node_count' => count($selectedNodes),
                'edge_count' => count($edges),
                'content_hash' => $contentHash,
                'metadata' => json_encode($datasetMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'is_active' => false,
                'graph_retained' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $nodeIds = [];
            foreach (array_chunk($selectedNodes, 500, true) as $chunk) {
                $rows = [];
                foreach ($chunk as $osmId => $node) {
                    $location = DB::connection()->getDriverName() === 'pgsql'
                        ? DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%.7F,%.7F),4326)::geography', $node['lng'], $node['lat']))
                        : json_encode(['type' => 'Point', 'coordinates' => [$node['lng'], $node['lat']]], JSON_THROW_ON_ERROR);
                    $rows[] = [
                        'dataset_id' => $datasetId,
                        'osm_node_id' => $osmId,
                        'latitude' => $node['lat'],
                        'longitude' => $node['lng'],
                        'is_walkable' => $node['walkable'],
                        'barrier' => $node['barrier'],
                        'crossing' => $node['crossing'],
                        'location' => $location,
                    ];
                }
                DB::table('pedestrian_network_nodes')->insert($rows);
            }
            $nodeIds = DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId)->pluck('id', 'osm_node_id')->all();

            foreach (array_chunk($edges, 400) as $chunk) {
                $rows = [];
                foreach ($chunk as $edge) {
                    $from = $selectedNodes[$edge['from']];
                    $to = $selectedNodes[$edge['to']];
                    $geometry = DB::connection()->getDriverName() === 'pgsql'
                        ? DB::raw(sprintf('ST_GeomFromText(\'LINESTRING(%.7F %.7F,%.7F %.7F)\',4326)', $from['lng'], $from['lat'], $to['lng'], $to['lat']))
                        : json_encode(['type' => 'LineString', 'coordinates' => [[$from['lng'], $from['lat']], [$to['lng'], $to['lat']]]], JSON_THROW_ON_ERROR);
                    $rows[] = [
                        'stable_id' => $stableId.'-'.$edge['way'].'-'.$edge['segment'],
                        'dataset_id' => $datasetId,
                        'osm_way_id' => $edge['way'],
                        'segment_index' => $edge['segment'],
                        'from_node_id' => $nodeIds[$edge['from']],
                        'to_node_id' => $nodeIds[$edge['to']],
                        'bidirectional' => $edge['bidirectional'],
                        'length_meters' => round($edge['length'], 2),
                        'highway' => $edge['highway'],
                        'properties' => json_encode($edge['properties'], JSON_THROW_ON_ERROR),
                        'min_latitude' => min($from['lat'], $to['lat']),
                        'min_longitude' => min($from['lng'], $to['lng']),
                        'max_latitude' => max($from['lat'], $to['lat']),
                        'max_longitude' => max($from['lng'], $to['lng']),
                        'geometry' => $geometry,
                    ];
                }
                DB::table('pedestrian_network_edges')->insert($rows);
            }

            $this->validateDatasetGraph($datasetId, count($selectedNodes), count($edges));
            DB::table('pedestrian_network_datasets')->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('pedestrian_network_datasets')->where('id', $datasetId)->update(['is_active' => true, 'updated_at' => now()]);

            return new PedestrianNetworkImportResult(
                $stableId,
                count($selectedNodes),
                count($edges),
                false,
                (int) $importMetrics['source_elements'],
                (int) $importMetrics['candidate_elements'],
                (int) $importMetrics['rejected_elements'],
                (int) $importMetrics['restricted_elements'],
                round((hrtime(true) - $startedAt) / 1_000_000, 2),
            );
        });
    }

    /**
     * Import a validated offline artifact as an inactive staging dataset.
     * The transaction is deliberately atomic: a failed batch cannot leave a
     * dataset that Doctor or the router could mistake for a complete graph.
     */
    public function importOfflineSqlite(string $path, string $sourcePath, string $stagingDatabase): PedestrianNetworkImportResult
    {
        $startedAt = hrtime(true);
        $this->assertOfflineStagingDatabase($stagingDatabase);
        $validation = (new OfflinePedestrianGraphValidator)->validate($path, $sourcePath);
        if (! $validation['routing_ready']) {
            throw new InvalidArgumentException('Offline graph restriction inventory is incomplete, ambiguous, or contains unenforced pedestrian restrictions; it is not importable.');
        }

        $sqlite = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $metadata = [];
        foreach ($sqlite->query('SELECT key, value FROM metadata') as $row) {
            $metadata[(string) $row['key']] = json_decode((string) $row['value'], true, 32, JSON_THROW_ON_ERROR);
        }
        $sourceUrl = (string) ($metadata['source_url'] ?? '');
        if (! $this->validSourceUrl($sourceUrl)) {
            throw new InvalidArgumentException('Offline graph does not contain a valid source URL.');
        }
        $nodesExpected = (int) $validation['nodes'];
        $edgesExpected = (int) $validation['edges'];
        $sourceElements = (int) ($metadata['source_nodes'] ?? 0) + (int) ($metadata['source_ways'] ?? 0) + (int) ($metadata['relations'] ?? 0);
        $contentHash = hash('sha256', (string) $validation['source_sha256'].'|'.(string) ($metadata['graph_content_sha256'] ?? ''));
        $stableId = 'osm-cebu-walk-'.substr($contentHash, 0, 32);
        $sourceTimestamp = $this->validTimestamp((string) ($metadata['source_timestamp'] ?? ''));
        $bounds = $sqlite->query('SELECT MIN(latitude) AS min_latitude, MIN(longitude) AS min_longitude, MAX(latitude) AS max_latitude, MAX(longitude) AS max_longitude FROM graph_nodes')->fetch(\PDO::FETCH_ASSOC);
        if (! is_array($bounds) || $bounds['min_latitude'] === null) {
            throw new InvalidArgumentException('Offline graph contains no spatially bounded nodes.');
        }
        $datasetMetadata = [
            'format' => 'osmium-osm-xml-v1',
            'source_details' => [
                'source' => 'OPENSTREETMAP', 'source_url' => $sourceUrl,
                'source_file' => $metadata['source_file'], 'source_checksum_sha256' => $validation['source_sha256'],
                'source_checksum_md5' => $metadata['source_md5'], 'source_timestamp' => $sourceTimestamp,
                'source_elements' => $sourceElements,
                'osmium_version' => $metadata['source_osmium_version'], 'extract_strategy' => $metadata['extract_strategy'],
                'extract_bounds' => $metadata['source_bounds'], 'license' => 'ODbL-1.0',
                'attribution' => '© OpenStreetMap contributors',
            ],
            'import_metrics' => [
                'source_elements' => $sourceElements, 'candidate_elements' => (int) ($metadata['candidate_ways'] ?? 0) + (int) ($metadata['scope_nodes'] ?? 0),
                'imported_nodes' => $nodesExpected, 'imported_edges' => $edgesExpected,
                'restricted_ways' => (int) ($metadata['restricted_ways'] ?? 0),
                'connected_components' => (int) $validation['components'],
            ],
            'restriction_audit' => [
                'policy_version' => OfflinePedestrianRestrictionPolicy::VERSION,
                'inventory_complete' => (bool) $validation['restriction_inventory_complete'],
                'source_relation_count' => (int) $validation['restriction_source_relation_count'],
                'stored_relation_count' => (int) $validation['restriction_counts']['total'],
                'counts' => $validation['restriction_counts'],
                'unsupported_applicable' => (int) $validation['unsupported_applicable_restrictions'],
                'ambiguous' => (int) $validation['ambiguous_restrictions'],
            ],
            'routing_capabilities' => $validation['routing_capabilities'],
            'routing_ready' => true,
            'offline_staging' => true,
            'activation' => 'NOT_ACTIVATED',
        ];

        return DB::transaction(function () use ($sqlite, $startedAt, $stableId, $sourceUrl, $sourceTimestamp, $contentHash, $bounds, $datasetMetadata, $nodesExpected, $edgesExpected, $sourceElements, $metadata): PedestrianNetworkImportResult {
            $existing = DB::table('pedestrian_network_datasets')->where('content_hash', $contentHash)->first();
            if ($existing !== null) {
                $existingMetadata = json_decode((string) $existing->metadata, true);
                $existingCapabilities = is_array($existingMetadata) && is_array($existingMetadata['routing_capabilities'] ?? null)
                    ? $existingMetadata['routing_capabilities']
                    : [];
                $existingRestrictionAudit = is_array($existingMetadata) && is_array($existingMetadata['restriction_audit'] ?? null)
                    ? $existingMetadata['restriction_audit']
                    : [];
                if ((bool) $existing->is_active
                    || ! is_array($existingMetadata)
                    || ($existingMetadata['offline_staging'] ?? false) !== true
                    || ($existingMetadata['routing_ready'] ?? false) !== true
                    || ($existingMetadata['activation'] ?? null) !== 'NOT_ACTIVATED'
                    || ($existingCapabilities['complete_restriction_inventory'] ?? false) !== true
                    || ($existingCapabilities['routing_ready'] ?? false) !== true
                    || ($existingCapabilities['turn_restrictions_needed'] ?? true) !== false
                    || ($existingRestrictionAudit['inventory_complete'] ?? false) !== true) {
                    throw new RuntimeException('An existing dataset with this fingerprint lacks safe inactive restriction-audit staging metadata.');
                }
                $this->validateDatasetGraph((int) $existing->id, $nodesExpected, $edgesExpected);

                return new PedestrianNetworkImportResult(
                    (string) $existing->stable_id, $nodesExpected, $edgesExpected, true,
                    $sourceElements, (int) ($metadata['candidate_ways'] ?? 0),
                    max(0, $sourceElements - $nodesExpected - (int) ($metadata['accepted_ways'] ?? 0)),
                    (int) ($metadata['restricted_ways'] ?? 0), round((hrtime(true) - $startedAt) / 1_000_000, 2),
                );
            }

            $datasetId = DB::table('pedestrian_network_datasets')->insertGetId([
                'stable_id' => $stableId, 'source' => 'OPENSTREETMAP', 'source_url' => $sourceUrl,
                'license' => 'ODbL-1.0', 'attribution' => '© OpenStreetMap contributors',
                'source_timestamp' => $sourceTimestamp, 'imported_at' => now(),
                'min_latitude' => $bounds['min_latitude'], 'min_longitude' => $bounds['min_longitude'],
                'max_latitude' => $bounds['max_latitude'], 'max_longitude' => $bounds['max_longitude'],
                'node_count' => $nodesExpected, 'edge_count' => $edgesExpected, 'content_hash' => $contentHash,
                'metadata' => json_encode($datasetMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'is_active' => false, 'graph_retained' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $nodeBatch = [];
            foreach ($sqlite->query('SELECT osm_node_id, latitude, longitude, walkable, barrier FROM graph_nodes ORDER BY osm_node_id') as $node) {
                $location = DB::connection()->getDriverName() === 'pgsql'
                    ? DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%.7F,%.7F),4326)::geography', (float) $node['longitude'], (float) $node['latitude']))
                    : json_encode(['type' => 'Point', 'coordinates' => [(float) $node['longitude'], (float) $node['latitude']]], JSON_THROW_ON_ERROR);
                $nodeBatch[] = [
                    'dataset_id' => $datasetId, 'osm_node_id' => (int) $node['osm_node_id'],
                    'latitude' => (float) $node['latitude'], 'longitude' => (float) $node['longitude'],
                    'is_walkable' => (bool) $node['walkable'], 'barrier' => $node['barrier'], 'location' => $location,
                ];
                if (count($nodeBatch) >= 100) {
                    DB::table('pedestrian_network_nodes')->insert($nodeBatch);
                    $nodeBatch = [];
                }
            }
            if ($nodeBatch !== []) {
                DB::table('pedestrian_network_nodes')->insert($nodeBatch);
            }

            $edgeBatch = [];
            foreach ($sqlite->query('SELECT osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt FROM graph_edges ORDER BY osm_way_id, segment_index') as $edge) {
                $edgeBatch[] = $edge;
                if (count($edgeBatch) >= 40) {
                    $this->insertOfflineEdgeBatch($datasetId, $stableId, $edgeBatch);
                    $edgeBatch = [];
                }
            }
            if ($edgeBatch !== []) {
                $this->insertOfflineEdgeBatch($datasetId, $stableId, $edgeBatch);
            }
            $this->validateDatasetGraph($datasetId, $nodesExpected, $edgesExpected);

            return new PedestrianNetworkImportResult(
                $stableId, $nodesExpected, $edgesExpected, false, $sourceElements,
                (int) ($metadata['candidate_ways'] ?? 0),
                max(0, $sourceElements - $nodesExpected - (int) ($metadata['accepted_ways'] ?? 0)),
                (int) ($metadata['restricted_ways'] ?? 0), round((hrtime(true) - $startedAt) / 1_000_000, 2),
            );
        });
    }

    private function assertOfflineStagingDatabase(string $stagingDatabase): void
    {
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        if ($stagingDatabase === ''
            || preg_match('/(?:^|[_\\/.-])(?:prod|production)(?:$|[_\\/.-])/i', $stagingDatabase) === 1
            || preg_match('/(?:^|[_\\/.-])(?:prod|production)(?:$|[_\\/.-])/i', $database) === 1
            || ! hash_equals($database, $stagingDatabase)) {
            throw new InvalidArgumentException('Offline graph import requires an explicit exact staging database identity; production-named databases are protected.');
        }
        if (app()->runningUnitTests() && $connection->getDriverName() === 'sqlite' && in_array($database, [':memory:', 'file::memory:'], true)) {
            return;
        }
        if ($connection->getDriverName() !== 'pgsql'
            || preg_match('/(?:^|[_\\/.-])(?:staging|test|testing|sandbox|scratch)(?:$|[_\\/.-])/i', $database) !== 1) {
            throw new InvalidArgumentException('Offline graph import target must be an isolated PostgreSQL staging/test database.');
        }
    }

    private function insertOfflineEdgeBatch(int $datasetId, string $stableId, array $edges): void
    {
        $osmNodeIds = [];
        foreach ($edges as $edge) {
            $osmNodeIds[] = (int) $edge['from_node_id'];
            $osmNodeIds[] = (int) $edge['to_node_id'];
        }
        $nodeRows = DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId)
            ->whereIn('osm_node_id', array_values(array_unique($osmNodeIds)))
            ->get(['id', 'osm_node_id', 'latitude', 'longitude']);
        $nodeIds = [];
        $coordinates = [];
        foreach ($nodeRows as $node) {
            $osmId = (int) $node->osm_node_id;
            $nodeIds[$osmId] = $node->id;
            $coordinates[$osmId] = ['latitude' => (float) $node->latitude, 'longitude' => (float) $node->longitude];
        }
        $rows = [];
        foreach ($edges as $edge) {
            $fromId = (int) $edge['from_node_id'];
            $toId = (int) $edge['to_node_id'];
            if (! isset($nodeIds[$fromId], $nodeIds[$toId])) {
                throw new RuntimeException('Offline graph import batch lost an endpoint node.');
            }
            $from = $coordinates[$fromId] ?? null;
            $to = $coordinates[$toId] ?? null;
            if ($from === null || $to === null) {
                throw new RuntimeException('Offline graph import could not resolve a node coordinate.');
            }
            $geometry = DB::connection()->getDriverName() === 'pgsql'
                ? DB::raw(sprintf('ST_GeomFromText(\'LINESTRING(%.7F %.7F,%.7F %.7F)\',4326)', $from['longitude'], $from['latitude'], $to['longitude'], $to['latitude']))
                : json_encode(['type' => 'LineString', 'coordinates' => [[$from['longitude'], $from['latitude']], [$to['longitude'], $to['latitude']]]], JSON_THROW_ON_ERROR);
            $rows[] = [
                'stable_id' => $stableId.'-'.$edge['osm_way_id'].'-'.$edge['segment_index'],
                'dataset_id' => $datasetId, 'osm_way_id' => (int) $edge['osm_way_id'],
                'segment_index' => (int) $edge['segment_index'], 'from_node_id' => $nodeIds[$fromId], 'to_node_id' => $nodeIds[$toId],
                'bidirectional' => (bool) $edge['bidirectional'], 'length_meters' => round((float) $edge['length_meters'], 2),
                'highway' => (string) $edge['highway'], 'properties' => (string) $edge['properties'],
                'min_latitude' => min($from['latitude'], $to['latitude']),
                'min_longitude' => min($from['longitude'], $to['longitude']),
                'max_latitude' => max($from['latitude'], $to['latitude']),
                'max_longitude' => max($from['longitude'], $to['longitude']), 'geometry' => $geometry,
            ];
        }
        DB::table('pedestrian_network_edges')->insert($rows);
    }

    /**
     * Merge a bounded new OSM extract with the active parsed graph. The active
     * graph remains the base; OSM node identity and coordinates are checked
     * before a replacement dataset is written transactionally.
     *
     * @param  array<int, array{lat: float, lng: float, walkable: bool, barrier: ?string, crossing: ?string}>  $newNodes
     * @param  list<array<string, mixed>>  $newEdges
     * @param  array<string, mixed>  $sourceMetadata
     * @return array{0: array<int, array{lat: float, lng: float, walkable: bool, barrier: ?string, crossing: ?string}>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function mergeActiveGraph(array $newNodes, array $newEdges, array $sourceMetadata = []): array
    {
        $active = DB::table('pedestrian_network_datasets')->where('is_active', true)->first();
        if ($active === null) {
            throw new InvalidArgumentException('Cannot merge a pedestrian extract without an active base dataset.');
        }

        $baseNodes = [];
        foreach (DB::table('pedestrian_network_nodes')
            ->where('dataset_id', $active->id)
            ->get(['osm_node_id', 'latitude', 'longitude', 'is_walkable', 'barrier', 'crossing']) as $node) {
            $baseNodes[(int) $node->osm_node_id] = [
                'lat' => (float) $node->latitude,
                'lng' => (float) $node->longitude,
                'walkable' => (bool) $node->is_walkable,
                'barrier' => $node->barrier,
                'crossing' => $node->crossing,
            ];
        }

        $edgeRows = DB::table('pedestrian_network_edges as edges')
            ->join('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
            ->join('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
            ->where('edges.dataset_id', $active->id)
            ->get([
                'edges.osm_way_id as way', 'edges.segment_index as segment', 'edges.bidirectional',
                'edges.length_meters as length', 'edges.highway', 'edges.properties',
                'from_nodes.osm_node_id as from_node', 'to_nodes.osm_node_id as to_node',
            ]);
        $baseEdges = [];
        foreach ($edgeRows as $edge) {
            $baseEdges[] = [
                'way' => (int) $edge->way,
                'segment' => (int) $edge->segment,
                'from' => (int) $edge->from_node,
                'to' => (int) $edge->to_node,
                'bidirectional' => (bool) $edge->bidirectional,
                'length' => (float) $edge->length,
                'highway' => (string) $edge->highway,
                'properties' => is_string($edge->properties) ? (json_decode($edge->properties, true) ?: []) : (array) $edge->properties,
            ];
        }

        $activeMetadata = json_decode((string) $active->metadata, true);
        $activeSource = is_array($activeMetadata) ? ($activeMetadata['source_details'] ?? []) : [];
        $sameSourceSnapshot = $sourceMetadata !== []
            && ($activeMetadata['merge_active_graph'] ?? false) === true
            && ($activeSource['source_url'] ?? null) === ($sourceMetadata['source_url'] ?? null)
            && ($activeSource['source_checksum_md5'] ?? null) === ($sourceMetadata['source_checksum_md5'] ?? null)
            && ($activeSource['source_checksum_sha256'] ?? null) === ($sourceMetadata['source_checksum_sha256'] ?? null)
            && ($activeSource['source_timestamp'] ?? null) === ($sourceMetadata['source_timestamp'] ?? null);
        if ($sameSourceSnapshot) {
            $activeMetrics = is_array($activeMetadata['import_metrics'] ?? null) ? $activeMetadata['import_metrics'] : [];
            $idempotentKeys = [
                'merge_base_dataset', 'merge_base_nodes', 'merge_base_edges',
                'merge_new_nodes', 'merge_new_edges',
                'merge_skipped_conflicting_nodes', 'merge_skipped_conflicting_edges',
            ];
            $idempotentMetadata = [];
            foreach ($idempotentKeys as $key) {
                if (! array_key_exists($key, $activeMetrics)) {
                    $idempotentMetadata = [];
                    break;
                }
                $idempotentMetadata[$key] = $activeMetrics[$key];
            }
            if ($idempotentMetadata !== []) {
                return [$baseNodes, $baseEdges, $idempotentMetadata];
            }
        }

        $baseNodeCount = count($baseNodes);
        $conflictingNodeIds = [];
        $acceptedNewNodeCount = 0;
        foreach ($newNodes as $nodeId => $node) {
            if (isset($baseNodes[$nodeId])) {
                $distance = Geo::distanceMeters($baseNodes[$nodeId]['lat'], $baseNodes[$nodeId]['lng'], $node['lat'], $node['lng']);
                if ($distance > 1.0) {
                    // Keep the active graph's coordinate and omit new edges
                    // that depend on a materially moved node. A refreshed
                    // extract must not silently move the established graph.
                    $conflictingNodeIds[(int) $nodeId] = true;

                    continue;
                }

                // Preserve the base coordinate even for small source drift.
                continue;
            }
            $baseNodes[$nodeId] = $node;
            $acceptedNewNodeCount++;
        }

        $edgeMap = [];
        $filteredNewEdges = array_values(array_filter($newEdges, static function (array $edge) use ($conflictingNodeIds): bool {
            return ! isset($conflictingNodeIds[(int) $edge['from']])
                && ! isset($conflictingNodeIds[(int) $edge['to']]);
        }));
        $edgeKey = static function (array $edge): string {
            return implode(':', [
                (int) $edge['way'],
                (int) $edge['segment'],
                (int) $edge['from'],
                (int) $edge['to'],
                ! empty($edge['bidirectional']) ? '1' : '0',
            ]);
        };
        foreach ($baseEdges as $edge) {
            if (! isset($baseNodes[(int) $edge['from']], $baseNodes[(int) $edge['to']])) {
                continue;
            }
            $edgeMap[$edgeKey($edge)] = $edge;
        }
        $baseEdgeKeyCount = count($edgeMap);
        foreach ($filteredNewEdges as $edge) {
            if (! isset($baseNodes[(int) $edge['from']], $baseNodes[(int) $edge['to']])) {
                continue;
            }
            $edgeMap[$edgeKey($edge)] = $edge;
        }
        $acceptedNewEdgeCount = max(0, count($edgeMap) - $baseEdgeKeyCount);

        return [
            $baseNodes,
            array_values($edgeMap),
            [
                'merge_base_dataset' => $active->stable_id,
                'merge_base_nodes' => $baseNodeCount,
                'merge_base_edges' => count($baseEdges),
                'merge_new_nodes' => $acceptedNewNodeCount,
                'merge_new_edges' => $acceptedNewEdgeCount,
                'merge_skipped_conflicting_nodes' => count($conflictingNodeIds),
                'merge_skipped_conflicting_edges' => count($newEdges) - count($filteredNewEdges),
            ],
        ];
    }

    private function validateDatasetGraph(int $datasetId, int $expectedNodes, int $expectedEdges): void
    {
        $nodeCount = DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId)->count();
        $edgeCount = DB::table('pedestrian_network_edges')->where('dataset_id', $datasetId)->count();
        $bounds = (array) config('biyahe.pedestrian.coverage');

        $invalidNodes = DB::table('pedestrian_network_nodes')
            ->where('dataset_id', $datasetId)
            ->where(function ($query) use ($bounds): void {
                $query->where('latitude', '<', (float) $bounds['min_latitude'])
                    ->orWhere('latitude', '>', (float) $bounds['max_latitude'])
                    ->orWhere('longitude', '<', (float) $bounds['min_longitude'])
                    ->orWhere('longitude', '>', (float) $bounds['max_longitude'])
                    ->orWhere('latitude', '<', -90)
                    ->orWhere('latitude', '>', 90)
                    ->orWhere('longitude', '<', -180)
                    ->orWhere('longitude', '>', 180);
            })
            ->count();

        $invalidEdges = DB::table('pedestrian_network_edges as edges')
            ->leftJoin('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
            ->leftJoin('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
            ->where('edges.dataset_id', $datasetId)
            ->where(function ($query): void {
                $query->whereNull('from_nodes.id')
                    ->orWhereNull('to_nodes.id')
                    ->orWhereColumn('from_nodes.dataset_id', '!=', 'edges.dataset_id')
                    ->orWhereColumn('to_nodes.dataset_id', '!=', 'edges.dataset_id')
                    ->orWhere('from_nodes.is_walkable', false)
                    ->orWhere('to_nodes.is_walkable', false)
                    ->orWhereColumn('edges.from_node_id', '=', 'edges.to_node_id')
                    ->orWhere('edges.length_meters', '<=', 0)
                    ->orWhere('edges.length_meters', '>', 10000)
                    ->orWhereNull('edges.geometry')
                    ->orWhereNull('edges.stable_id')
                    ->orWhere('edges.stable_id', '');
            })
            ->count();

        $issues = [];
        if ($nodeCount !== $expectedNodes || $edgeCount !== $expectedEdges) {
            $issues[] = "expected {$expectedNodes} nodes/{$expectedEdges} edges, found {$nodeCount}/{$edgeCount}";
        }
        if ($nodeCount === 0 || $edgeCount === 0) {
            $issues[] = 'the graph is empty';
        }
        if ($invalidNodes > 0) {
            $issues[] = "{$invalidNodes} nodes have invalid coordinates or exceed the configured bounds";
        }
        if ($invalidEdges > 0) {
            $issues[] = "{$invalidEdges} edges have invalid lengths, geometry, or endpoint references";
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $invalidSpatialNodes = DB::table('pedestrian_network_nodes')
                ->where('dataset_id', $datasetId)
                ->whereRaw("location IS NULL OR ST_SRID(location::geometry) <> 4326 OR ST_GeometryType(location::geometry) <> 'ST_Point' OR ST_IsEmpty(location::geometry) OR NOT ST_IsValid(location::geometry)")
                ->count();
            $invalidSpatialEdges = DB::table('pedestrian_network_edges')
                ->where('dataset_id', $datasetId)
                ->whereRaw("geometry IS NULL OR ST_SRID(geometry) <> 4326 OR ST_GeometryType(geometry) <> 'ST_LineString' OR ST_IsEmpty(geometry) OR NOT ST_IsValid(geometry)")
                ->count();
            $misalignedEdges = DB::table('pedestrian_network_edges as edges')
                ->join('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
                ->join('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
                ->where('edges.dataset_id', $datasetId)
                ->whereRaw('ST_Distance(ST_StartPoint(edges.geometry)::geography, from_nodes.location) > 0.05 OR ST_Distance(ST_EndPoint(edges.geometry)::geography, to_nodes.location) > 0.05')
                ->count();

            if ($invalidSpatialNodes > 0) {
                $issues[] = "{$invalidSpatialNodes} nodes have invalid PostGIS geometry or SRID";
            }
            if ($invalidSpatialEdges > 0) {
                $issues[] = "{$invalidSpatialEdges} edges have invalid PostGIS geometry or SRID";
            }
            if ($misalignedEdges > 0) {
                $issues[] = "{$misalignedEdges} edge geometries do not match their node endpoints";
            }
        }

        if ($issues !== []) {
            throw new RuntimeException('Pedestrian graph validation failed before activation: '.implode('; ', $issues).'.');
        }
    }

    /** @return array{array<int, array{lat: float, lng: float, walkable: bool, barrier: ?string, crossing: ?string}>, array<int, array{nodes: list<int>, tags: array<string, string>}>, array{input_nodes: int, input_ways: int, restricted_nodes: int, restriction_relations: int, vehicle_only_restrictions: int, pedestrian_exempt_restrictions: int}} */
    private function parseElements(array $elements): array
    {
        $maxNodes = max(1, (int) config('biyahe.pedestrian.maximum_input_nodes', 50000));
        $maxWays = max(1, (int) config('biyahe.pedestrian.maximum_input_ways', 25000));
        $nodes = [];
        $ways = [];
        $metrics = [
            'input_nodes' => 0,
            'input_ways' => 0,
            'restricted_nodes' => 0,
            'restriction_relations' => 0,
            'vehicle_only_restrictions' => 0,
            'pedestrian_exempt_restrictions' => 0,
        ];
        foreach ($elements as $element) {
            if (! is_array($element) || ! is_int($element['id'] ?? null) || $element['id'] <= 0) {
                throw new InvalidArgumentException('Every OSM element must have a positive integer ID.');
            }
            if (($element['type'] ?? null) === 'node') {
                $metrics['input_nodes']++;
                if (count($nodes) >= $maxNodes) {
                    throw new InvalidArgumentException('OSM input exceeds the configured node limit.');
                }
                $latitude = $element['lat'] ?? null;
                $longitude = $element['lon'] ?? null;
                if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
                    || (float) $latitude < -90 || (float) $latitude > 90 || (float) $longitude < -180 || (float) $longitude > 180) {
                    throw new InvalidArgumentException('OSM nodes require valid WGS84 coordinates.');
                }
                if (isset($nodes[$element['id']])) {
                    throw new InvalidArgumentException('Duplicate OSM node ID in input.');
                }
                $tags = $this->tags($element['tags'] ?? []);
                $walkable = ! $this->blockedNode($tags);
                if (! $walkable) {
                    $metrics['restricted_nodes']++;
                }
                $nodes[$element['id']] = [
                    'lat' => (float) $latitude,
                    'lng' => (float) $longitude,
                    'walkable' => $walkable,
                    'barrier' => $tags['barrier'] ?? null,
                    'crossing' => $tags['crossing'] ?? (($tags['highway'] ?? null) === 'crossing' ? 'MAPPED' : null),
                ];
            } elseif (($element['type'] ?? null) === 'way') {
                $metrics['input_ways']++;
                if (count($ways) >= $maxWays) {
                    throw new InvalidArgumentException('OSM input exceeds the configured way limit.');
                }
                if (isset($ways[$element['id']])) {
                    throw new InvalidArgumentException('Duplicate OSM way ID in input.');
                }
                $refs = $element['nodes'] ?? null;
                if (! is_array($refs) || count($refs) < 2 || array_filter($refs, static fn ($id): bool => ! is_int($id) || $id <= 0) !== []) {
                    throw new InvalidArgumentException('OSM ways require at least two positive node references.');
                }
                $ways[$element['id']] = ['nodes' => array_values($refs), 'tags' => $this->tags($element['tags'] ?? [])];
            } elseif (($element['type'] ?? null) === 'relation') {
                $tags = $this->tags($element['tags'] ?? []);
                $isRestriction = ($tags['type'] ?? null) === 'restriction';
                foreach (array_keys($tags) as $key) {
                    $isRestriction = $isRestriction || $key === 'restriction' || str_starts_with($key, 'restriction:');
                }
                if (! $isRestriction) {
                    continue;
                }
                $members = [];
                foreach (is_array($element['members'] ?? null) ? $element['members'] : [] as $member) {
                    if (! is_array($member)) {
                        continue;
                    }
                    $members[] = [
                        'member_type' => (string) ($member['type'] ?? ''),
                        'member_ref' => is_numeric($member['ref'] ?? null) ? (int) $member['ref'] : 0,
                        'role' => (string) ($member['role'] ?? ''),
                    ];
                }
                $classification = OfflinePedestrianRestrictionPolicy::classify($tags, $members);
                if ($classification['unsupported_for_routing']) {
                    throw new InvalidArgumentException('Input contains a pedestrian-applicable or mode-ambiguous turn restriction (relation '.$element['id'].'); routing import is blocked.');
                }
                $metrics['restriction_relations']++;
                if ($classification['mode_applicability'] === 'vehicle_only') {
                    $metrics['vehicle_only_restrictions']++;
                } elseif ($classification['mode_applicability'] === 'pedestrian_exempt') {
                    $metrics['pedestrian_exempt_restrictions']++;
                }
            }
        }

        return [$nodes, $ways, $metrics];
    }

    /** @return array{array<int, array{lat: float, lng: float, walkable: bool, barrier: ?string, crossing: ?string}>, list<array<string, mixed>>, int, int} */
    private function buildGraph(array $nodes, array $ways): array
    {
        $scope = config('biyahe.pedestrian.coverage');
        $selectedNodes = [];
        $edges = [];
        $acceptedWays = 0;
        $restrictedWays = 0;
        foreach ($ways as $wayId => $way) {
            $tags = $way['tags'];
            if ($this->explicitlyRestrictedWay($tags)) {
                $restrictedWays++;
            }
            if (! $this->walkableWay($tags)) {
                continue;
            }
            $refs = $way['nodes'];
            foreach ($refs as $reference) {
                if (! isset($nodes[$reference])) {
                    throw new InvalidArgumentException('A pedestrian way references a node missing from the extract. Request complete ways and nodes.');
                }
            }
            [$forward, $backward] = $this->directions($tags);
            if (! $forward && ! $backward) {
                continue;
            }
            $highway = $tags['highway'];
            $properties = array_intersect_key($tags, array_flip([
                'surface', 'smoothness', 'bridge', 'tunnel', 'layer', 'sidewalk', 'footway', 'crossing', 'crossing:markings', 'crossing:signals', 'foot', 'access', 'oneway:foot', 'foot:forward', 'foot:backward',
            ]));
            $edgesBeforeWay = count($edges);
            for ($index = 0; $index < count($refs) - 1; $index++) {
                $fromId = $refs[$index];
                $toId = $refs[$index + 1];
                $from = $nodes[$fromId];
                $to = $nodes[$toId];
                if (! $from['walkable'] || ! $to['walkable'] || ! $this->insideScope($from, $scope) || ! $this->insideScope($to, $scope)) {
                    continue;
                }
                $length = Geo::distanceMeters($from['lat'], $from['lng'], $to['lat'], $to['lng']);
                if (! is_finite($length) || $length < 0.1 || $length > 10000) {
                    continue;
                }
                if (count($edges) >= max(1, (int) config('biyahe.pedestrian.maximum_input_edges', 100000))) {
                    throw new InvalidArgumentException('OSM input exceeds the configured pedestrian-edge limit.');
                }
                $selectedNodes[$fromId] = $from;
                $selectedNodes[$toId] = $to;
                if ($forward && $backward) {
                    $edges[] = ['way' => $wayId, 'segment' => $index, 'from' => $fromId, 'to' => $toId, 'bidirectional' => true, 'length' => $length, 'highway' => $highway, 'properties' => $properties];
                } elseif ($forward) {
                    $edges[] = ['way' => $wayId, 'segment' => $index, 'from' => $fromId, 'to' => $toId, 'bidirectional' => false, 'length' => $length, 'highway' => $highway, 'properties' => $properties];
                } else {
                    $edges[] = ['way' => $wayId, 'segment' => $index, 'from' => $toId, 'to' => $fromId, 'bidirectional' => false, 'length' => $length, 'highway' => $highway, 'properties' => $properties];
                }
            }
            if (count($edges) > $edgesBeforeWay) {
                $acceptedWays++;
            }
        }

        foreach ($nodes as $nodeId => $node) {
            if (! $node['walkable'] && $this->insideScope($node, $scope)) {
                $selectedNodes[$nodeId] = $node;
            }
        }

        return [$selectedNodes, $edges, $acceptedWays, $restrictedWays];
    }

    private function explicitlyRestrictedWay(array $tags): bool
    {
        return PedestrianOsmPolicy::explicitlyRestrictedWay($tags);
    }

    private function walkableWay(array $tags): bool
    {
        return PedestrianOsmPolicy::walkableWay($tags);
    }

    /** @return array{bool, bool} */
    private function directions(array $tags): array
    {
        return PedestrianOsmPolicy::directions($tags);
    }

    private function blockedNode(array $tags): bool
    {
        return PedestrianOsmPolicy::blockedNode($tags);
    }

    private function insideScope(array $node, array $scope): bool
    {
        return PedestrianOsmPolicy::insideScope($node['lat'], $node['lng'], $scope);
    }

    private function tags(mixed $tags): array
    {
        return PedestrianOsmPolicy::tags($tags);
    }

    /** @return array<string, mixed> */
    private function sourceMetadata(array $document, string $sourceUrl): array
    {
        if (! array_key_exists('biyahe_source', $document)) {
            return [];
        }
        $metadata = $document['biyahe_source'];
        if (! is_array($metadata)) {
            throw new InvalidArgumentException('Biyahe OSM source metadata must be an object.');
        }
        if (($metadata['test_only'] ?? false) === true) {
            throw new InvalidArgumentException('A test-only pedestrian extract cannot be imported or activated.');
        }

        $format = $metadata['format'] ?? null;
        $metadataUrl = $metadata['source_url'] ?? null;
        $sourceFile = $metadata['source_file'] ?? null;
        $checksum = $metadata['source_checksum_md5'] ?? null;
        $sha256 = $metadata['source_checksum_sha256'] ?? null;
        $sourceTimestamp = $metadata['source_timestamp'] ?? null;
        $sourceElements = $metadata['source_elements'] ?? null;
        $osmiumVersion = $metadata['osmium_version'] ?? null;
        $extractBounds = $metadata['extract_bounds'] ?? null;
        $configuredBounds = config('biyahe.pedestrian.coverage');

        $extractStrategy = $metadata['extract_strategy'] ?? null;
        if ($format !== 'osmium-osm-xml-v1' || ($metadata['source'] ?? null) !== 'OPENSTREETMAP'
            || ! is_string($metadataUrl) || $metadataUrl !== $sourceUrl || ! $this->validSourceUrl($metadataUrl)
            || ! is_string($sourceFile) || $sourceFile === '' || strlen($sourceFile) > 255
            || ! in_array($extractStrategy, ['complete_ways', 'streaming_sqlite_ref_complete', 'offline_scope_first_sqlite_v3'], true)
            || ($metadata['license'] ?? null) !== self::LICENSE || ($metadata['attribution'] ?? null) !== self::ATTRIBUTION
            || ! is_array($extractBounds) || $extractBounds !== $configuredBounds) {
            throw new InvalidArgumentException('Biyahe OSM metadata is incomplete or inconsistent with the import source.');
        }
        if (! is_string($checksum) || ! preg_match('/^[a-f0-9]{32}$/i', $checksum)) {
            throw new InvalidArgumentException('Biyahe OSM source MD5 metadata is malformed.');
        }
        if (($sha256 !== null && (! is_string($sha256) || ! preg_match('/^[a-f0-9]{64}$/i', $sha256)))
            || (in_array($extractStrategy, ['streaming_sqlite_ref_complete', 'offline_scope_first_sqlite_v3'], true) && ! is_string($sha256))) {
            throw new InvalidArgumentException('Biyahe OSM source SHA-256 metadata is malformed.');
        }
        if (! is_string($sourceTimestamp) || trim($sourceTimestamp) === '') {
            throw new InvalidArgumentException('Biyahe OSM source timestamp metadata is malformed.');
        }
        if (! is_int($sourceElements) || $sourceElements < 0 || $sourceElements > 10_000_000) {
            throw new InvalidArgumentException('Biyahe OSM source element count is outside the supported bound.');
        }
        if (! is_string($osmiumVersion) || trim($osmiumVersion) === '' || strlen($osmiumVersion) > 80) {
            throw new InvalidArgumentException('Biyahe Osmium version metadata is malformed.');
        }

        return [
            'format' => $format,
            'source' => 'OPENSTREETMAP',
            'source_url' => $metadataUrl,
            'source_file' => basename($sourceFile),
            'source_checksum_md5' => $checksum === null ? null : strtolower($checksum),
            'source_checksum_sha256' => $sha256 === null ? null : strtolower($sha256),
            'source_timestamp' => $this->validTimestamp($sourceTimestamp),
            'source_elements' => $sourceElements,
            'osmium_version' => $osmiumVersion,
            'extract_strategy' => $extractStrategy,
            'extract_bounds' => $extractBounds,
            'license' => self::LICENSE,
            'attribution' => self::ATTRIBUTION,
        ];
    }

    private function validSourceUrl(string $url): bool
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }

    private function validTimestamp(string $value): ?string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new InvalidArgumentException('OSM snapshot timestamp is malformed.');
        }
    }

    private function bounds(array $nodes): array
    {
        $latitudes = array_column($nodes, 'lat');
        $longitudes = array_column($nodes, 'lng');

        return [
            'min_latitude' => min($latitudes),
            'min_longitude' => min($longitudes),
            'max_latitude' => max($latitudes),
            'max_longitude' => max($longitudes),
        ];
    }
}
