<?php

namespace App\Services\Routing;

use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class PedestrianNetworkImporter
{
    private const ATTRIBUTION = '© OpenStreetMap contributors';

    private const LICENSE = 'ODbL-1.0';

    private const OFFLINE_IMPORT_CHECKPOINT_VERSION = 1;

    private const OFFLINE_IMPORT_SCHEMA_VERSION = 'offline-pedestrian-import-v1';

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
     * Stream a validated offline graph into bounded transactions. Incomplete
     * imports stay inactive. A retry verifies the committed source prefix
     * before continuing; an explicit restart may discard only matching
     * importer-owned staging rows.
     *
     * @param  null|callable(string, array<string, int|string|null>): void  $progressObserver
     */
    public function importOfflineSqlite(
        string $path,
        string $sourcePath,
        string $stagingDatabase,
        ?string $expectedGraphSha256 = null,
        ?string $expectedSourceSha256 = null,
        ?callable $progressObserver = null,
        bool $restartIncomplete = false,
    ): PedestrianNetworkImportResult {
        $startedAt = hrtime(true);
        $this->assertOfflineStagingDatabase($stagingDatabase);
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $lockName = 'biyahe:offline-pedestrian-import';
        $lockHeld = false;
        if ($driver === 'pgsql') {
            $lock = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS acquired', [$lockName]);
            if (! is_object($lock) || ! $this->databaseBoolean($lock->acquired ?? false)) {
                throw new RuntimeException('Another offline pedestrian graph import is already running.');
            }
            $lockHeld = true;
        }

        try {
            if (! is_file($path) || ! is_readable($path) || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
                throw new InvalidArgumentException('Offline graph and its local source PBF must both be readable files.');
            }
            if ($expectedGraphSha256 !== null && ! preg_match('/^[a-f0-9]{64}$/i', $expectedGraphSha256)) {
                throw new InvalidArgumentException('Expected graph SHA-256 must contain 64 hexadecimal characters.');
            }
            $actualGraphSha256 = hash_file('sha256', $path);
            if (! is_string($actualGraphSha256)
                || ($expectedGraphSha256 !== null && ! hash_equals(strtolower($expectedGraphSha256), strtolower($actualGraphSha256)))) {
                throw new InvalidArgumentException('Offline graph artifact SHA-256 does not match the expected identity.');
            }
            $validation = (new OfflinePedestrianGraphValidator)->validate($path, $sourcePath, $expectedSourceSha256);
            if (! ($validation['routing_ready'] ?? false)) {
                throw new InvalidArgumentException('Offline graph restriction inventory is incomplete, ambiguous, or contains unenforced pedestrian restrictions; it is not importable.');
            }
            $validatedGraphSha256 = hash_file('sha256', $path);
            if (! is_string($validatedGraphSha256) || ! hash_equals(strtolower($actualGraphSha256), strtolower($validatedGraphSha256))) {
                throw new InvalidArgumentException('Offline graph artifact changed while it was being validated.');
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
            $sourceSha256 = strtolower((string) $validation['source_sha256']);
            $graphFingerprint = strtolower((string) $validation['graph_fingerprint']);
            $actualGraphSha256 = strtolower($actualGraphSha256);
            $contentHash = hash('sha256', $sourceSha256.'|'.$graphFingerprint);
            $stableId = 'osm-cebu-walk-'.substr($contentHash, 0, 32);
            $sourceTimestamp = $this->validTimestamp((string) ($metadata['source_timestamp'] ?? ''));
            $nodeBatchSize = min(1000, max(1, (int) config('biyahe.pedestrian.offline_import_node_batch_size', 100)));
            $edgeBatchSize = min(500, max(1, (int) config('biyahe.pedestrian.offline_import_edge_batch_size', 40)));
            $bounds = $sqlite->query('SELECT MIN(latitude) AS min_latitude, MIN(longitude) AS min_longitude, MAX(latitude) AS max_latitude, MAX(longitude) AS max_longitude FROM graph_nodes')->fetch(\PDO::FETCH_ASSOC);
            if (! is_array($bounds) || $bounds['min_latitude'] === null) {
                throw new InvalidArgumentException('Offline graph contains no spatially bounded nodes.');
            }
            $importProgress = [
                'checkpoint_version' => self::OFFLINE_IMPORT_CHECKPOINT_VERSION,
                'expected_nodes' => $nodesExpected, 'expected_edges' => $edgesExpected,
                'nodes_committed' => 0, 'edges_committed' => 0,
                'node_batches_committed' => 0, 'edge_batches_committed' => 0,
                'node_batch_size' => $nodeBatchSize, 'edge_batch_size' => $edgeBatchSize,
                'last_node_osm_id' => null, 'last_edge_osm_way_id' => null, 'last_edge_segment_index' => null,
                'phase' => 'PENDING', 'elapsed_milliseconds' => 0,
            ];
            $importCheckpoint = [
                'version' => self::OFFLINE_IMPORT_CHECKPOINT_VERSION,
                'import_schema' => self::OFFLINE_IMPORT_SCHEMA_VERSION,
                'graph_format' => OfflinePedestrianGraphCheckpoint::FORMAT,
                'graph_schema_version' => (int) ($metadata['schema_version'] ?? 0),
                'graph_sha256' => $actualGraphSha256,
                'graph_fingerprint' => $graphFingerprint,
                'source_sha256' => $sourceSha256,
                'expected_nodes' => $nodesExpected,
                'expected_edges' => $edgesExpected,
            ];
            $datasetMetadata = [
                'format' => 'osmium-osm-xml-v1',
                'source_details' => [
                    'source' => 'OPENSTREETMAP', 'source_url' => $sourceUrl,
                    'source_file' => $metadata['source_file'], 'source_checksum_sha256' => $sourceSha256,
                    'source_checksum_md5' => $metadata['source_md5'], 'source_timestamp' => $sourceTimestamp,
                    'source_elements' => $sourceElements,
                    'osmium_version' => $metadata['source_osmium_version'], 'extract_strategy' => $metadata['extract_strategy'],
                    'extract_bounds' => $metadata['source_bounds'], 'license' => 'ODbL-1.0',
                    'attribution' => '© OpenStreetMap contributors',
                ],
                'graph_sha256' => $actualGraphSha256,
                'graph_fingerprint' => $graphFingerprint,
                'import_checkpoint' => $importCheckpoint,
                'import_state' => 'PENDING',
                'import_progress' => $importProgress,
                'import_recovery' => ['restart_count' => 0, 'last_recovered_state' => null],
                'import_metrics' => [
                    'source_elements' => $sourceElements,
                    'candidate_elements' => (int) ($metadata['candidate_ways'] ?? 0) + (int) ($metadata['scope_nodes'] ?? 0),
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
                'routing_ready' => false,
                'offline_staging' => true,
                'activation' => 'NOT_ACTIVATED',
            ];

            $dataset = DB::table('pedestrian_network_datasets')->where('content_hash', $contentHash)->first();
            if ($dataset !== null) {
                $existingMetadata = is_array($dataset->metadata)
                    ? $dataset->metadata
                    : json_decode((string) $dataset->metadata, true);
                if (! is_array($existingMetadata)) {
                    throw new RuntimeException('Existing staging metadata is malformed; refusing to reuse or remove its rows.');
                }
                $existingCapabilities = is_array($existingMetadata) && is_array($existingMetadata['routing_capabilities'] ?? null)
                    ? $existingMetadata['routing_capabilities'] : [];
                $existingRestrictionAudit = is_array($existingMetadata) && is_array($existingMetadata['restriction_audit'] ?? null)
                    ? $existingMetadata['restriction_audit'] : [];
                $identityMatches = is_array($existingMetadata)
                    && ($existingMetadata['offline_staging'] ?? false) === true
                    && ($existingMetadata['activation'] ?? null) === 'NOT_ACTIVATED'
                    && hash_equals($stableId, (string) $dataset->stable_id)
                    && hash_equals($contentHash, strtolower((string) $dataset->content_hash))
                    && (int) $dataset->node_count === $nodesExpected
                    && (int) $dataset->edge_count === $edgesExpected
                    && hash_equals($actualGraphSha256, strtolower((string) ($existingMetadata['graph_sha256'] ?? '')))
                    && hash_equals($graphFingerprint, strtolower((string) ($existingMetadata['graph_fingerprint'] ?? '')))
                    && hash_equals($sourceSha256, strtolower((string) ($existingMetadata['source_details']['source_checksum_sha256'] ?? '')))
                    && (int) ($existingMetadata['import_metrics']['imported_nodes'] ?? -1) === $nodesExpected
                    && (int) ($existingMetadata['import_metrics']['imported_edges'] ?? -1) === $edgesExpected;
                if ((bool) $dataset->is_active || ! $identityMatches) {
                    throw new RuntimeException('An existing content fingerprint has conflicting or unsafe staging ownership metadata.');
                }

                $existingState = (string) ($existingMetadata['import_state'] ?? '');
                if ($existingState === 'VALIDATED') {
                    if (($existingMetadata['routing_ready'] ?? false) !== true
                        || ! $this->offlineCheckpointMatches($existingMetadata['import_checkpoint'] ?? null, $importCheckpoint)
                        || ($existingCapabilities['complete_restriction_inventory'] ?? false) !== true
                        || ($existingCapabilities['routing_ready'] ?? false) !== true
                        || ($existingCapabilities['turn_restrictions_needed'] ?? true) !== false
                        || ($existingRestrictionAudit['inventory_complete'] ?? false) !== true) {
                        throw new RuntimeException('An existing validated dataset lacks safe inactive restriction-audit staging metadata.');
                    }
                    $this->validateDatasetGraph((int) $dataset->id, $nodesExpected, $edgesExpected);
                    $this->validateDatasetMatchesOfflineSqlite($sqlite, (int) $dataset->id, $nodesExpected, $edgesExpected, $progressObserver);
                    if (($existingMetadata['staging_integrity']['nodes_match'] ?? false) !== true
                        || ($existingMetadata['staging_integrity']['edges_match'] ?? false) !== true
                        || ($existingMetadata['staging_integrity']['directionality_match'] ?? false) !== true
                        || ($existingMetadata['staging_integrity']['geometry_match'] ?? false) !== true
                        || (int) ($existingMetadata['staging_integrity']['nodes_verified'] ?? -1) !== $nodesExpected
                        || (int) ($existingMetadata['staging_integrity']['edges_verified'] ?? -1) !== $edgesExpected
                        || ! hash_equals($graphFingerprint, strtolower((string) ($existingMetadata['staging_integrity']['graph_fingerprint'] ?? '')))) {
                        throw new RuntimeException('Existing validated staging lacks exact SQLite-to-PostgreSQL graph comparison evidence.');
                    }

                    return new PedestrianNetworkImportResult(
                        (string) $dataset->stable_id, $nodesExpected, $edgesExpected, true,
                        $sourceElements, (int) ($metadata['candidate_ways'] ?? 0),
                        max(0, $sourceElements - $nodesExpected - (int) ($metadata['accepted_ways'] ?? 0)),
                        (int) ($metadata['restricted_ways'] ?? 0), round((hrtime(true) - $startedAt) / 1_000_000, 2),
                    );
                }
                if (! in_array($existingState, ['PENDING', 'IMPORTING', 'INTERRUPTED', 'FAILED', 'STAGED_INACTIVE'], true)) {
                    throw new RuntimeException('Existing graph staging has no recognized incomplete import state; refusing to reuse or remove it.');
                }

                $recovery = is_array($existingMetadata['import_recovery'] ?? null) ? $existingMetadata['import_recovery'] : [];
                $datasetId = (int) $dataset->id;
                if ($restartIncomplete) {
                    $recovery['restart_count'] = (int) ($recovery['restart_count'] ?? 0) + 1;
                    $recovery['last_recovered_state'] = $existingState;
                    $recovery['last_restart_at'] = now()->toIso8601String();
                    $datasetMetadata['import_recovery'] = $recovery;
                    DB::transaction(function () use ($datasetId, &$datasetMetadata, $importProgress): void {
                        DB::table('pedestrian_network_edges')->where('dataset_id', $datasetId)->delete();
                        DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId)->delete();
                        $datasetMetadata['import_state'] = 'PENDING';
                        $datasetMetadata['routing_ready'] = false;
                        $datasetMetadata['import_progress'] = $importProgress;
                        unset($datasetMetadata['failure'], $datasetMetadata['staging_integrity'], $datasetMetadata['validated_at']);
                        $this->persistOfflineImportMetadata($datasetId, $datasetMetadata);
                    });
                } else {
                    if (! $this->offlineCheckpointMatches($existingMetadata['import_checkpoint'] ?? null, $importCheckpoint)) {
                        throw new RuntimeException('Existing partial staging has no compatible source-bound checkpoint; inspect it and pass --restart-incomplete only to explicitly restart matching inactive rows.');
                    }
                    $this->validateOfflineImportProgress($existingMetadata['import_progress'] ?? null, $nodesExpected, $edgesExpected);
                    $this->validateOfflineCheckpointPrefix($sqlite, $datasetId, $existingMetadata['import_progress'], $progressObserver);
                    $recovery['resume_count'] = (int) ($recovery['resume_count'] ?? 0) + 1;
                    $recovery['last_recovered_state'] = $existingState;
                    $recovery['last_resume_at'] = now()->toIso8601String();
                    $datasetMetadata = $existingMetadata;
                    $datasetMetadata['import_recovery'] = $recovery;
                    $datasetMetadata['import_state'] = 'INTERRUPTED';
                    $datasetMetadata['routing_ready'] = false;
                    unset($datasetMetadata['failure'], $datasetMetadata['staging_integrity'], $datasetMetadata['validated_at']);
                    DB::transaction(fn () => $this->persistOfflineImportMetadata($datasetId, $datasetMetadata));
                    $importProgress = $datasetMetadata['import_progress'];
                    $nodeBatchSize = (int) $importProgress['node_batch_size'];
                    $edgeBatchSize = (int) $importProgress['edge_batch_size'];
                }
            } else {
                if ($restartIncomplete) {
                    throw new InvalidArgumentException('--restart-incomplete requires an existing matching inactive dataset.');
                }
                $datasetId = DB::transaction(fn (): int => DB::table('pedestrian_network_datasets')->insertGetId([
                    'stable_id' => $stableId, 'source' => 'OPENSTREETMAP', 'source_url' => $sourceUrl,
                    'license' => 'ODbL-1.0', 'attribution' => '© OpenStreetMap contributors',
                    'source_timestamp' => $sourceTimestamp, 'imported_at' => now(),
                    'min_latitude' => $bounds['min_latitude'], 'min_longitude' => $bounds['min_longitude'],
                    'max_latitude' => $bounds['max_latitude'], 'max_longitude' => $bounds['max_longitude'],
                    'node_count' => $nodesExpected, 'edge_count' => $edgesExpected, 'content_hash' => $contentHash,
                    'metadata' => json_encode($datasetMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'is_active' => false, 'graph_retained' => true, 'created_at' => now(), 'updated_at' => now(),
                ]));
            }

            $this->emitOfflineImportProgress(
                $progressObserver,
                $datasetMetadata['import_state'] === 'INTERRUPTED' ? 'INTERRUPTED' : 'PENDING',
                $datasetMetadata['import_progress'],
            );
            try {
                $datasetMetadata['import_state'] = 'IMPORTING';
                $datasetMetadata['import_progress']['phase'] = $datasetMetadata['import_progress']['nodes_committed'] < $nodesExpected
                    ? 'NODES'
                    : ($datasetMetadata['import_progress']['edges_committed'] < $edgesExpected ? 'EDGES' : 'VALIDATING');
                DB::transaction(fn () => $this->persistOfflineImportMetadata($datasetId, $datasetMetadata));
                if ($datasetMetadata['import_progress']['nodes_committed'] < $nodesExpected) {
                    $lastNodeId = $datasetMetadata['import_progress']['last_node_osm_id'];
                    $nodeStatement = $sqlite->prepare('SELECT osm_node_id, latitude, longitude, walkable, barrier, crossing FROM graph_nodes WHERE osm_node_id > :last_id ORDER BY osm_node_id');
                    $nodeStatement->bindValue(':last_id', $lastNodeId === null ? 0 : (int) $lastNodeId, \PDO::PARAM_INT);
                    $nodeStatement->execute();
                    $nodeBatch = [];
                    while (($node = $nodeStatement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                        $location = $driver === 'pgsql'
                            ? DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%.7F,%.7F),4326)::geography', (float) $node['longitude'], (float) $node['latitude']))
                            : json_encode(['type' => 'Point', 'coordinates' => [(float) $node['longitude'], (float) $node['latitude']]], JSON_THROW_ON_ERROR);
                        $nodeBatch[] = [
                            'dataset_id' => $datasetId, 'osm_node_id' => (int) $node['osm_node_id'],
                            'latitude' => (float) $node['latitude'], 'longitude' => (float) $node['longitude'],
                            'is_walkable' => (bool) $node['walkable'], 'barrier' => $node['barrier'],
                            'crossing' => $node['crossing'], 'location' => $location,
                        ];
                        if (count($nodeBatch) >= $nodeBatchSize) {
                            $this->commitOfflineNodeBatch($datasetId, $datasetMetadata, $nodeBatch, $progressObserver, $startedAt);
                            $nodeBatch = [];
                        }
                    }
                    if ($nodeBatch !== []) {
                        $this->commitOfflineNodeBatch($datasetId, $datasetMetadata, $nodeBatch, $progressObserver, $startedAt);
                    }
                }

                $datasetMetadata['import_progress']['phase'] = 'EDGES';
                DB::transaction(fn () => $this->persistOfflineImportMetadata($datasetId, $datasetMetadata));
                if ($datasetMetadata['import_progress']['edges_committed'] < $edgesExpected) {
                    $lastWayId = $datasetMetadata['import_progress']['last_edge_osm_way_id'];
                    $lastSegment = $datasetMetadata['import_progress']['last_edge_segment_index'];
                    $edgeStatement = $sqlite->prepare('SELECT osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt FROM graph_edges WHERE osm_way_id > :last_way OR (osm_way_id = :last_way AND segment_index > :last_segment) ORDER BY osm_way_id, segment_index');
                    $edgeStatement->bindValue(':last_way', $lastWayId === null ? 0 : (int) $lastWayId, \PDO::PARAM_INT);
                    $edgeStatement->bindValue(':last_segment', $lastSegment === null ? -1 : (int) $lastSegment, \PDO::PARAM_INT);
                    $edgeStatement->execute();
                    $edgeBatch = [];
                    while (($edge = $edgeStatement->fetch(\PDO::FETCH_ASSOC)) !== false) {
                        $edgeBatch[] = $edge;
                        if (count($edgeBatch) >= $edgeBatchSize) {
                            $this->commitOfflineEdgeBatch($datasetId, $stableId, $datasetMetadata, $edgeBatch, $progressObserver, $startedAt);
                            $edgeBatch = [];
                        }
                    }
                    if ($edgeBatch !== []) {
                        $this->commitOfflineEdgeBatch($datasetId, $stableId, $datasetMetadata, $edgeBatch, $progressObserver, $startedAt);
                    }
                }

                $datasetMetadata['import_state'] = 'STAGED_INACTIVE';
                $datasetMetadata['import_progress']['phase'] = 'VALIDATING';
                $this->persistOfflineImportMetadata($datasetId, $datasetMetadata);
                $this->emitOfflineImportProgress($progressObserver, 'STAGED_INACTIVE', $datasetMetadata['import_progress']);
                $this->validateDatasetGraph($datasetId, $nodesExpected, $edgesExpected);
                $datasetMetadata['staging_integrity'] = $this->validateDatasetMatchesOfflineSqlite($sqlite, $datasetId, $nodesExpected, $edgesExpected, $progressObserver);
                $datasetMetadata['staging_integrity']['graph_fingerprint'] = $graphFingerprint;
                $datasetMetadata['import_state'] = 'VALIDATED';
                $datasetMetadata['routing_ready'] = true;
                $datasetMetadata['validated_at'] = now()->toIso8601String();
                $datasetMetadata['import_progress']['phase'] = 'VALIDATED';
                $datasetMetadata['import_progress']['elapsed_milliseconds'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);
                DB::transaction(fn () => $this->persistOfflineImportMetadata($datasetId, $datasetMetadata));
                $this->emitOfflineImportProgress($progressObserver, 'VALIDATED', $datasetMetadata['import_progress']);
            } catch (Throwable $exception) {
                try {
                    $datasetMetadata['import_state'] = 'FAILED';
                    $datasetMetadata['routing_ready'] = false;
                    $datasetMetadata['failure'] = [
                        'type' => class_basename($exception),
                        'recorded_at' => now()->toIso8601String(),
                        'phase' => $datasetMetadata['import_progress']['phase'] ?? 'UNKNOWN',
                    ];
                    DB::transaction(fn () => $this->persistOfflineImportMetadata($datasetId, $datasetMetadata));
                } catch (Throwable) {
                    // The last committed state remains inactive and is reconciled on a later retry.
                }
                throw $exception;
            }

            return new PedestrianNetworkImportResult(
                $stableId, $nodesExpected, $edgesExpected, false, $sourceElements,
                (int) ($metadata['candidate_ways'] ?? 0),
                max(0, $sourceElements - $nodesExpected - (int) ($metadata['accepted_ways'] ?? 0)),
                (int) ($metadata['restricted_ways'] ?? 0), round((hrtime(true) - $startedAt) / 1_000_000, 2),
            );
        } finally {
            if ($lockHeld) {
                DB::selectOne('SELECT pg_advisory_unlock(hashtext(?))', [$lockName]);
            }
        }
    }

    private function offlineCheckpointMatches(mixed $stored, array $expected): bool
    {
        if (! is_array($stored) || count($stored) !== count($expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $stored) || $stored[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function validateOfflineImportProgress(mixed $progress, int $expectedNodes, int $expectedEdges): void
    {
        $integerFields = [
            'expected_nodes', 'expected_edges', 'nodes_committed', 'edges_committed', 'node_batches_committed', 'edge_batches_committed',
            'node_batch_size', 'edge_batch_size', 'elapsed_milliseconds',
        ];
        if (! is_array($progress) || ($progress['checkpoint_version'] ?? null) !== self::OFFLINE_IMPORT_CHECKPOINT_VERSION) {
            throw new RuntimeException('Existing staging progress does not use the supported checkpoint format; use an explicit safe restart after inspection.');
        }
        foreach ($integerFields as $field) {
            if (! is_int($progress[$field] ?? null) || $progress[$field] < 0) {
                throw new RuntimeException('Existing staging checkpoint contains an invalid '.$field.' value.');
            }
        }
        $nodes = $progress['nodes_committed'];
        $edges = $progress['edges_committed'];
        $nodeBatchSize = $progress['node_batch_size'];
        $edgeBatchSize = $progress['edge_batch_size'];
        $phase = $progress['phase'] ?? null;
        $validPhases = ['PENDING', 'NODES', 'EDGES', 'VALIDATING', 'VALIDATED'];
        if (! in_array($phase, $validPhases, true)
            || $progress['expected_nodes'] !== $expectedNodes || $progress['expected_edges'] !== $expectedEdges
            || $nodes > $expectedNodes || $edges > $expectedEdges
            || $nodeBatchSize < 1 || $nodeBatchSize > 1000
            || $edgeBatchSize < 1 || $edgeBatchSize > 500
            || $progress['node_batches_committed'] !== ($nodes === 0 ? 0 : (int) ceil($nodes / $nodeBatchSize))
            || $progress['edge_batches_committed'] !== ($edges === 0 ? 0 : (int) ceil($edges / $edgeBatchSize))) {
            throw new RuntimeException('Existing staging checkpoint counts, phase, or batch boundaries are inconsistent.');
        }
        $lastNode = $progress['last_node_osm_id'] ?? null;
        $lastWay = $progress['last_edge_osm_way_id'] ?? null;
        $lastSegment = $progress['last_edge_segment_index'] ?? null;
        if (($nodes === 0 && $lastNode !== null)
            || ($nodes > 0 && (! is_int($lastNode) || $lastNode < 1))
            || ($edges === 0 && ($lastWay !== null || $lastSegment !== null))
            || ($edges > 0 && (! is_int($lastWay) || $lastWay < 1 || ! is_int($lastSegment) || $lastSegment < 0))
            || ($edges > 0 && $nodes !== $expectedNodes)
            || (in_array($phase, ['EDGES', 'VALIDATING', 'VALIDATED'], true) && $nodes !== $expectedNodes)
            || (in_array($phase, ['VALIDATING', 'VALIDATED'], true) && $edges !== $expectedEdges)
            || ($phase === 'PENDING' && ($nodes !== 0 || $edges !== 0))) {
            throw new RuntimeException('Existing staging checkpoint last keys do not match its completed phase and row counts.');
        }
    }

    /** Verify every committed row against the exact ordered source prefix in bounded pages. */
    private function validateOfflineCheckpointPrefix(\PDO $sqlite, int $datasetId, array $progress, ?callable $observer): void
    {
        $nodeCount = (int) $progress['nodes_committed'];
        $edgeCount = (int) $progress['edges_committed'];
        $storedNodeCount = (int) DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId)->count();
        $storedEdgeCount = (int) DB::table('pedestrian_network_edges')->where('dataset_id', $datasetId)->count();
        if ($storedNodeCount !== $nodeCount || $storedEdgeCount !== $edgeCount) {
            throw new RuntimeException('Existing staging row counts do not match the committed checkpoint; refusing to skip or delete rows.');
        }

        $nodeSource = $sqlite->query('SELECT osm_node_id, latitude, longitude, walkable, barrier, crossing FROM graph_nodes ORDER BY osm_node_id');
        $lastNodeId = null;
        $nodesVerified = 0;
        while ($nodesVerified < $nodeCount) {
            $limit = min(500, $nodeCount - $nodesVerified);
            $query = DB::table('pedestrian_network_nodes')->where('dataset_id', $datasetId);
            if ($lastNodeId !== null) {
                $query->where('osm_node_id', '>', $lastNodeId);
            }
            $databaseNodes = $query->orderBy('osm_node_id')->limit($limit)
                ->get(['osm_node_id', 'latitude', 'longitude', 'is_walkable', 'barrier', 'crossing']);
            if ($databaseNodes->isEmpty()) {
                throw new RuntimeException('Existing node checkpoint prefix ended before its committed count.');
            }
            foreach ($databaseNodes as $databaseNode) {
                $sourceNode = $nodeSource->fetch(\PDO::FETCH_ASSOC);
                if (! is_array($sourceNode)
                    || (int) $databaseNode->osm_node_id !== (int) $sourceNode['osm_node_id']
                    || abs((float) $databaseNode->latitude - (float) $sourceNode['latitude']) > 0.0000001
                    || abs((float) $databaseNode->longitude - (float) $sourceNode['longitude']) > 0.0000001
                    || $this->databaseBoolean($databaseNode->is_walkable) !== ((int) $sourceNode['walkable'] === 1)
                    || ($databaseNode->barrier === null ? null : (string) $databaseNode->barrier) !== ($sourceNode['barrier'] === null ? null : (string) $sourceNode['barrier'])
                    || ($databaseNode->crossing === null ? null : (string) $databaseNode->crossing) !== ($sourceNode['crossing'] === null ? null : (string) $sourceNode['crossing'])) {
                    throw new RuntimeException('Existing node checkpoint rows are not the exact SQLite source prefix.');
                }
                $lastNodeId = (int) $databaseNode->osm_node_id;
                $nodesVerified++;
            }
            $this->emitOfflineImportProgress($observer, 'CHECKPOINT_NODES', [
                'nodes_verified' => $nodesVerified, 'nodes_committed' => $nodeCount,
                'edges_verified' => 0, 'edges_committed' => $edgeCount,
            ]);
        }
        if (($nodeCount === 0 && ($progress['last_node_osm_id'] ?? null) !== null)
            || ($nodeCount > 0 && $lastNodeId !== $progress['last_node_osm_id'])) {
            throw new RuntimeException('Existing node checkpoint last key does not match the verified source prefix.');
        }

        $edgeSource = $sqlite->query('SELECT osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt FROM graph_edges ORDER BY osm_way_id, segment_index');
        $lastWayId = null;
        $lastSegment = null;
        $edgesVerified = 0;
        while ($edgesVerified < $edgeCount) {
            $limit = min(500, $edgeCount - $edgesVerified);
            $query = DB::table('pedestrian_network_edges as edges')
                ->join('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
                ->join('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
                ->where('edges.dataset_id', $datasetId)
                ->where('from_nodes.dataset_id', $datasetId)
                ->where('to_nodes.dataset_id', $datasetId);
            if ($lastWayId !== null) {
                $query->where(function ($nested) use ($lastWayId, $lastSegment): void {
                    $nested->where('edges.osm_way_id', '>', $lastWayId)
                        ->orWhere(function ($sameWay) use ($lastWayId, $lastSegment): void {
                            $sameWay->where('edges.osm_way_id', $lastWayId)->where('edges.segment_index', '>', $lastSegment);
                        });
                });
            }
            $databaseEdgesQuery = $query->orderBy('edges.osm_way_id')->orderBy('edges.segment_index')->limit($limit)
                ->select([
                    'edges.osm_way_id', 'edges.segment_index', 'from_nodes.osm_node_id as from_osm_node_id',
                    'to_nodes.osm_node_id as to_osm_node_id', 'edges.bidirectional', 'edges.length_meters',
                    'edges.highway', 'edges.properties', 'edges.geometry',
                ]);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $databaseEdgesQuery->selectRaw('ST_X(ST_StartPoint(edges.geometry)) AS start_longitude, ST_Y(ST_StartPoint(edges.geometry)) AS start_latitude, ST_X(ST_EndPoint(edges.geometry)) AS end_longitude, ST_Y(ST_EndPoint(edges.geometry)) AS end_latitude');
            }
            $databaseEdges = $databaseEdgesQuery->get();
            if ($databaseEdges->isEmpty()) {
                throw new RuntimeException('Existing edge checkpoint prefix ended before its committed count.');
            }
            foreach ($databaseEdges as $databaseEdge) {
                $sourceEdge = $edgeSource->fetch(\PDO::FETCH_ASSOC);
                if (! is_array($sourceEdge)) {
                    throw new RuntimeException('Existing edge checkpoint contains rows beyond the SQLite source prefix.');
                }
                $sourceProperties = json_decode((string) $sourceEdge['properties'], true, 32, JSON_THROW_ON_ERROR);
                $databaseProperties = is_array($databaseEdge->properties)
                    ? $databaseEdge->properties
                    : json_decode((string) $databaseEdge->properties, true, 32, JSON_THROW_ON_ERROR);
                $geometry = $this->databaseGeometryEndpoints($databaseEdge);
                $sourceGeometry = $this->wktLineEndpoints((string) $sourceEdge['geometry_wkt']);
                if ((int) $databaseEdge->osm_way_id !== (int) $sourceEdge['osm_way_id']
                    || (int) $databaseEdge->segment_index !== (int) $sourceEdge['segment_index']
                    || (int) $databaseEdge->from_osm_node_id !== (int) $sourceEdge['from_node_id']
                    || (int) $databaseEdge->to_osm_node_id !== (int) $sourceEdge['to_node_id']
                    || $this->databaseBoolean($databaseEdge->bidirectional) !== ((int) $sourceEdge['bidirectional'] === 1)
                    || abs((float) $databaseEdge->length_meters - (float) $sourceEdge['length_meters']) > 0.0051
                    || (string) $databaseEdge->highway !== (string) $sourceEdge['highway']
                    || $databaseProperties != $sourceProperties
                    || $geometry === null || $sourceGeometry === null
                    || abs($geometry[0] - $sourceGeometry[0]) > 0.0000001
                    || abs($geometry[1] - $sourceGeometry[1]) > 0.0000001
                    || abs($geometry[2] - $sourceGeometry[2]) > 0.0000001
                    || abs($geometry[3] - $sourceGeometry[3]) > 0.0000001) {
                    throw new RuntimeException('Existing edge checkpoint direction, properties, length, endpoints, or geometry differs from its SQLite source prefix.');
                }
                $lastWayId = (int) $databaseEdge->osm_way_id;
                $lastSegment = (int) $databaseEdge->segment_index;
                $edgesVerified++;
            }
            $this->emitOfflineImportProgress($observer, 'CHECKPOINT_EDGES', [
                'nodes_verified' => $nodesVerified, 'nodes_committed' => $nodeCount,
                'edges_verified' => $edgesVerified, 'edges_committed' => $edgeCount,
            ]);
        }
        if (($edgeCount === 0 && (($progress['last_edge_osm_way_id'] ?? null) !== null || ($progress['last_edge_segment_index'] ?? null) !== null))
            || ($edgeCount > 0 && ($lastWayId !== $progress['last_edge_osm_way_id'] || $lastSegment !== $progress['last_edge_segment_index']))) {
            throw new RuntimeException('Existing edge checkpoint last key does not match the verified source prefix.');
        }
    }

    private function commitOfflineNodeBatch(int $datasetId, array &$metadata, array $batch, ?callable $observer, int $startedAt): void
    {
        $nextMetadata = $metadata;
        $lastNode = $batch[array_key_last($batch)];
        DB::transaction(function () use ($datasetId, &$nextMetadata, $batch, $lastNode, $startedAt): void {
            DB::table('pedestrian_network_nodes')->insert($batch);
            $nextMetadata['import_progress']['nodes_committed'] += count($batch);
            $nextMetadata['import_progress']['node_batches_committed']++;
            $nextMetadata['import_progress']['last_node_osm_id'] = (int) $lastNode['osm_node_id'];
            $nextMetadata['import_progress']['phase'] = 'NODES';
            $nextMetadata['import_progress']['elapsed_milliseconds'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $this->persistOfflineImportMetadata($datasetId, $nextMetadata);
        });
        $metadata = $nextMetadata;
        $this->emitOfflineImportProgress($observer, 'NODES', $metadata['import_progress']);
    }

    private function commitOfflineEdgeBatch(int $datasetId, string $stableId, array &$metadata, array $batch, ?callable $observer, int $startedAt): void
    {
        $nextMetadata = $metadata;
        $lastEdge = $batch[array_key_last($batch)];
        DB::transaction(function () use ($datasetId, $stableId, &$nextMetadata, $batch, $lastEdge, $startedAt): void {
            $this->insertOfflineEdgeBatch($datasetId, $stableId, $batch);
            $nextMetadata['import_progress']['edges_committed'] += count($batch);
            $nextMetadata['import_progress']['edge_batches_committed']++;
            $nextMetadata['import_progress']['last_edge_osm_way_id'] = (int) $lastEdge['osm_way_id'];
            $nextMetadata['import_progress']['last_edge_segment_index'] = (int) $lastEdge['segment_index'];
            $nextMetadata['import_progress']['phase'] = 'EDGES';
            $nextMetadata['import_progress']['elapsed_milliseconds'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $this->persistOfflineImportMetadata($datasetId, $nextMetadata);
        });
        $metadata = $nextMetadata;
        $this->emitOfflineImportProgress($observer, 'EDGES', $metadata['import_progress']);
    }

    private function persistOfflineImportMetadata(int $datasetId, array $metadata): void
    {
        $updated = DB::table('pedestrian_network_datasets')
            ->where('id', $datasetId)
            ->where('is_active', false)
            ->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Offline staging state update lost its inactive dataset row.');
        }
    }

    private function emitOfflineImportProgress(?callable $observer, string $phase, array $progress): void
    {
        if ($observer !== null) {
            $observer($phase, $progress);
        }
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
        if ((string) $connection->getConfig('host') !== '127.0.0.1') {
            throw new InvalidArgumentException('Offline graph import is restricted to a loopback isolated PostgreSQL staging/test server.');
        }
        $expectedPgData = getenv('BIYAHE_ISOLATED_PGDATA');
        if (! is_string($expectedPgData) || $expectedPgData === '' || realpath($expectedPgData) === false) {
            throw new InvalidArgumentException('Offline graph import requires BIYAHE_ISOLATED_PGDATA for a separately initialized disposable cluster.');
        }
        $server = DB::selectOne("SELECT current_setting('data_directory') AS data_directory");
        $actualPgData = is_object($server) ? realpath((string) ($server->data_directory ?? '')) : false;
        $expectedPgData = realpath($expectedPgData);
        if ($actualPgData === false || $expectedPgData === false
            || ! hash_equals($expectedPgData, $actualPgData)
            || preg_match('#/\.cache/biyahe/batch033-isolated-[A-Za-z0-9_-]+/data$#', $actualPgData) !== 1) {
            throw new InvalidArgumentException('Connected PostgreSQL data_directory does not match the new Batch033 isolated cluster path.');
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

    /** Compare every staged source ID, access flag, direction, property set, length, and geometry endpoint in bounded chunks. */
    private function validateDatasetMatchesOfflineSqlite(\PDO $sqlite, int $datasetId, int $expectedNodes, int $expectedEdges, ?callable $observer): array
    {
        $batchSize = 500;
        $nodeSource = $sqlite->query('SELECT osm_node_id, latitude, longitude, walkable, barrier, crossing FROM graph_nodes ORDER BY osm_node_id');
        $lastNodeId = 0;
        $nodesVerified = 0;
        while (true) {
            $databaseNodes = DB::table('pedestrian_network_nodes')
                ->where('dataset_id', $datasetId)
                ->where('osm_node_id', '>', $lastNodeId)
                ->orderBy('osm_node_id')
                ->limit($batchSize)
                ->get(['osm_node_id', 'latitude', 'longitude', 'is_walkable', 'barrier', 'crossing']);
            if ($databaseNodes->isEmpty()) {
                break;
            }
            foreach ($databaseNodes as $databaseNode) {
                $sourceNode = $nodeSource->fetch(\PDO::FETCH_ASSOC);
                if (! is_array($sourceNode)
                    || (int) $databaseNode->osm_node_id !== (int) $sourceNode['osm_node_id']
                    || abs((float) $databaseNode->latitude - (float) $sourceNode['latitude']) > 0.0000001
                    || abs((float) $databaseNode->longitude - (float) $sourceNode['longitude']) > 0.0000001
                    || $this->databaseBoolean($databaseNode->is_walkable) !== ((int) $sourceNode['walkable'] === 1)
                    || ($databaseNode->barrier === null ? null : (string) $databaseNode->barrier) !== ($sourceNode['barrier'] === null ? null : (string) $sourceNode['barrier'])
                    || ($databaseNode->crossing === null ? null : (string) $databaseNode->crossing) !== ($sourceNode['crossing'] === null ? null : (string) $sourceNode['crossing'])) {
                    throw new RuntimeException('Staged pedestrian node data differs from the validated immutable SQLite artifact.');
                }
                $lastNodeId = (int) $databaseNode->osm_node_id;
                $nodesVerified++;
            }
            $this->emitOfflineImportProgress($observer, 'VERIFY_NODES', [
                'nodes_verified' => $nodesVerified, 'expected_nodes' => $expectedNodes,
                'edges_verified' => 0, 'expected_edges' => $expectedEdges,
            ]);
        }
        if ($nodesVerified !== $expectedNodes || $nodeSource->fetch(\PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('Staged node inventory does not exactly match the SQLite graph node sequence.');
        }

        $edgeSource = $sqlite->query('SELECT osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt FROM graph_edges ORDER BY osm_way_id, segment_index');
        $lastWayId = 0;
        $lastSegment = -1;
        $edgesVerified = 0;
        while (true) {
            $databaseEdgesQuery = DB::table('pedestrian_network_edges as edges')
                ->join('pedestrian_network_nodes as from_nodes', 'from_nodes.id', '=', 'edges.from_node_id')
                ->join('pedestrian_network_nodes as to_nodes', 'to_nodes.id', '=', 'edges.to_node_id')
                ->where('edges.dataset_id', $datasetId)
                ->where('from_nodes.dataset_id', $datasetId)
                ->where('to_nodes.dataset_id', $datasetId)
                ->where(function ($query) use ($lastWayId, $lastSegment): void {
                    $query->where('edges.osm_way_id', '>', $lastWayId)
                        ->orWhere(function ($nested) use ($lastWayId, $lastSegment): void {
                            $nested->where('edges.osm_way_id', $lastWayId)->where('edges.segment_index', '>', $lastSegment);
                        });
                })
                ->orderBy('edges.osm_way_id')
                ->orderBy('edges.segment_index')
                ->limit($batchSize)
                ->select([
                    'edges.osm_way_id', 'edges.segment_index', 'from_nodes.osm_node_id as from_osm_node_id',
                    'to_nodes.osm_node_id as to_osm_node_id', 'edges.bidirectional', 'edges.length_meters',
                    'edges.highway', 'edges.properties', 'edges.geometry',
                ]);
            if (DB::connection()->getDriverName() === 'pgsql') {
                $databaseEdgesQuery->selectRaw('ST_X(ST_StartPoint(edges.geometry)) AS start_longitude, ST_Y(ST_StartPoint(edges.geometry)) AS start_latitude, ST_X(ST_EndPoint(edges.geometry)) AS end_longitude, ST_Y(ST_EndPoint(edges.geometry)) AS end_latitude');
            }
            $databaseEdges = $databaseEdgesQuery->get();
            if ($databaseEdges->isEmpty()) {
                break;
            }
            foreach ($databaseEdges as $databaseEdge) {
                $sourceEdge = $edgeSource->fetch(\PDO::FETCH_ASSOC);
                if (! is_array($sourceEdge)) {
                    throw new RuntimeException('Staged graph contains extra edges absent from the SQLite artifact.');
                }
                $sourceProperties = json_decode((string) $sourceEdge['properties'], true, 32, JSON_THROW_ON_ERROR);
                $databaseProperties = is_array($databaseEdge->properties)
                    ? $databaseEdge->properties
                    : json_decode((string) $databaseEdge->properties, true, 32, JSON_THROW_ON_ERROR);
                $geometry = $this->databaseGeometryEndpoints($databaseEdge);
                $sourceGeometry = $this->wktLineEndpoints((string) $sourceEdge['geometry_wkt']);
                if ((int) $databaseEdge->osm_way_id !== (int) $sourceEdge['osm_way_id']
                    || (int) $databaseEdge->segment_index !== (int) $sourceEdge['segment_index']
                    || (int) $databaseEdge->from_osm_node_id !== (int) $sourceEdge['from_node_id']
                    || (int) $databaseEdge->to_osm_node_id !== (int) $sourceEdge['to_node_id']
                    || $this->databaseBoolean($databaseEdge->bidirectional) !== ((int) $sourceEdge['bidirectional'] === 1)
                    || abs((float) $databaseEdge->length_meters - (float) $sourceEdge['length_meters']) > 0.0051
                    || (string) $databaseEdge->highway !== (string) $sourceEdge['highway']
                    || $databaseProperties != $sourceProperties
                    || $geometry === null || $sourceGeometry === null
                    || abs($geometry[0] - $sourceGeometry[0]) > 0.0000001
                    || abs($geometry[1] - $sourceGeometry[1]) > 0.0000001
                    || abs($geometry[2] - $sourceGeometry[2]) > 0.0000001
                    || abs($geometry[3] - $sourceGeometry[3]) > 0.0000001) {
                    throw new RuntimeException('Staged pedestrian edge direction, properties, length, endpoints, or geometry differs from the SQLite graph.');
                }
                $lastWayId = (int) $databaseEdge->osm_way_id;
                $lastSegment = (int) $databaseEdge->segment_index;
                $edgesVerified++;
            }
            $this->emitOfflineImportProgress($observer, 'VERIFY_EDGES', [
                'nodes_verified' => $nodesVerified, 'expected_nodes' => $expectedNodes,
                'edges_verified' => $edgesVerified, 'expected_edges' => $expectedEdges,
            ]);
        }
        if ($edgesVerified !== $expectedEdges || $edgeSource->fetch(\PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('Staged edge inventory does not exactly match the SQLite graph edge sequence.');
        }

        return [
            'nodes_match' => true,
            'edges_match' => true,
            'directionality_match' => true,
            'geometry_match' => true,
            'nodes_verified' => $nodesVerified,
            'edges_verified' => $edgesVerified,
        ];
    }

    private function databaseBoolean(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['1', 't', 'true', 'yes'], true);
    }

    private function databaseGeometryEndpoints(object $edge): ?array
    {
        if (isset($edge->start_longitude, $edge->start_latitude, $edge->end_longitude, $edge->end_latitude)) {
            return [(float) $edge->start_longitude, (float) $edge->start_latitude, (float) $edge->end_longitude, (float) $edge->end_latitude];
        }
        $geometry = is_string($edge->geometry ?? null) ? json_decode($edge->geometry, true) : $edge->geometry;
        $coordinates = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;
        if (($geometry['type'] ?? null) !== 'LineString' || ! is_array($coordinates) || count($coordinates) !== 2) {
            return null;
        }

        return [(float) $coordinates[0][0], (float) $coordinates[0][1], (float) $coordinates[1][0], (float) $coordinates[1][1]];
    }

    private function wktLineEndpoints(string $wkt): ?array
    {
        if (preg_match('/^LINESTRING\\(\\s*(-?\\d+(?:\\.\\d+)?)\\s+(-?\\d+(?:\\.\\d+)?),\\s*(-?\\d+(?:\\.\\d+)?)\\s+(-?\\d+(?:\\.\\d+)?)\\s*\\)$/i', trim($wkt), $matches) !== 1) {
            return null;
        }

        return [(float) $matches[1], (float) $matches[2], (float) $matches[3], (float) $matches[4]];
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
