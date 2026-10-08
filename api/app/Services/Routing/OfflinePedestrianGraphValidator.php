<?php

namespace App\Services\Routing;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Validates offline artifacts before anyone offers them to a database importer. */
final class OfflinePedestrianGraphValidator
{
    /** @return array<string, int|string|bool> */
    public function validate(string $path, string $sourcePath, ?string $expectedSha256 = null): array
    {
        if (! is_file($path) || ! is_readable($path) || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException('Offline graph and its local source PBF must both be readable files.');
        }
        $sqlite = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec('PRAGMA temp_store=FILE');
        $sqlite->exec('PRAGMA cache_size=-8192');
        $integrity = (string) $sqlite->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new RuntimeException('Offline SQLite integrity check failed: '.$integrity);
        }
        $metadata = $this->metadata($sqlite);
        if (($metadata['format'] ?? null) !== OfflinePedestrianGraphCheckpoint::FORMAT
            || (int) ($metadata['schema_version'] ?? 0) !== OfflinePedestrianGraphCheckpoint::SCHEMA_VERSION
            || ($metadata['state'] ?? null) !== 'COMPLETE'
            || ($metadata['input_complete'] ?? false) !== true
            || ($metadata['way_index_complete'] ?? false) !== true
            || ($metadata['references_complete'] ?? false) !== true
            || ($metadata['crs'] ?? null) !== 'EPSG:4326') {
            throw new RuntimeException('Offline graph artifact is incomplete or uses an unsupported format.');
        }

        $actualHash = hash_file('sha256', $sourcePath);
        $actualMd5 = hash_file('md5', $sourcePath);
        $storedHash = strtolower((string) ($metadata['source_sha256'] ?? ''));
        if (! is_string($actualHash) || ! preg_match('/^[a-f0-9]{64}$/', $storedHash)
            || ! hash_equals($storedHash, strtolower($actualHash))
            || ! is_string($actualMd5) || ! preg_match('/^[a-f0-9]{32}$/', strtolower((string) ($metadata['source_md5'] ?? '')))
            || ! hash_equals(strtolower((string) $metadata['source_md5']), strtolower($actualMd5))
            || ($expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), strtolower($actualHash)))) {
            throw new RuntimeException('Offline graph source fingerprint does not match the verified local PBF.');
        }
        $sourceUrl = (string) ($metadata['source_url'] ?? '');
        $sourceScheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
        $timestamp = $metadata['source_timestamp'] ?? null;
        $timestampValid = false;
        try {
            if (is_string($timestamp) && trim($timestamp) !== '') {
                new DateTimeImmutable($timestamp);
                $timestampValid = true;
            }
        } catch (\Throwable) {
            $timestampValid = false;
        }
        if (basename($sourcePath) !== ($metadata['source_file'] ?? null)
            || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false || ! in_array($sourceScheme, ['http', 'https'], true)
            || basename(rawurldecode((string) parse_url($sourceUrl, PHP_URL_PATH))) !== basename($sourcePath)
            || ! $timestampValid || preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($metadata['source_osmium_version'] ?? '')) !== 1
            || ($metadata['source_bounds'] ?? null) !== config('biyahe.pedestrian.coverage')
            || ($metadata['license'] ?? null) !== 'ODbL-1.0'
            || ($metadata['attribution'] ?? null) !== '© OpenStreetMap contributors') {
            throw new RuntimeException('Offline graph provenance metadata is incomplete or inconsistent.');
        }

        (new OfflinePedestrianGraphCheckpoint)->verifyCompleteArtifact($sqlite, [
            'url' => $sourceUrl,
            'file' => basename($sourcePath),
            'sha256' => $storedHash,
            'md5' => strtolower($actualMd5),
            'timestamp' => (new DateTimeImmutable((string) $timestamp))->format(DATE_ATOM),
            'osmium_version' => (string) $metadata['source_osmium_version'],
            'bounds' => $metadata['source_bounds'],
        ]);

        $nodes = (int) $sqlite->query('SELECT COUNT(*) FROM graph_nodes')->fetchColumn();
        $edges = (int) $sqlite->query('SELECT COUNT(*) FROM graph_edges')->fetchColumn();
        if ($nodes === 0 || $edges === 0 || $nodes !== (int) ($metadata['graph_nodes'] ?? -1) || $edges !== (int) ($metadata['graph_edges'] ?? -1)) {
            throw new RuntimeException('Offline graph counts do not match the completed staging metadata.');
        }
        $indexedSourceNodes = (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes')->fetchColumn();
        $scopeNodes = (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes WHERE in_scope=1')->fetchColumn();
        $candidateWays = (int) $sqlite->query('SELECT COUNT(*) FROM source_ways')->fetchColumn();
        $references = (int) $sqlite->query('SELECT COUNT(*) FROM way_refs')->fetchColumn();
        $requiredNodes = (int) $sqlite->query('SELECT COUNT(*) FROM required_nodes')->fetchColumn();
        $resolvedNodes = (int) $sqlite->query('SELECT COUNT(*) FROM resolved_node_ids')->fetchColumn();
        $sourceNodes = (int) ($metadata['source_nodes'] ?? -1);
        if ($sourceNodes < $scopeNodes
            || $indexedSourceNodes !== (int) ($metadata['indexed_source_nodes'] ?? -1)
            || $scopeNodes !== (int) ($metadata['scope_nodes'] ?? -1)
            || $candidateWays === 0 || $candidateWays !== (int) ($metadata['candidate_ways'] ?? -1)
            || $references === 0 || $requiredNodes !== (int) ($metadata['required_nodes'] ?? -1)
            || $resolvedNodes !== $requiredNodes || $resolvedNodes !== (int) ($metadata['resolved_nodes'] ?? -1)
            || (int) $sqlite->query('SELECT COUNT(*) FROM seen_way_ids')->fetchColumn() !== (int) ($metadata['source_ways'] ?? -1)
            || (int) $sqlite->query('SELECT COUNT(*) FROM seen_relation_ids')->fetchColumn() !== (int) ($metadata['relations'] ?? -1)) {
            throw new RuntimeException('Offline source index counts do not match the completed input checkpoint.');
        }
        $missingReferences = (int) $sqlite->query('SELECT COUNT(*) FROM way_refs AS refs LEFT JOIN source_nodes AS nodes ON nodes.id=refs.node_id WHERE nodes.id IS NULL')->fetchColumn();
        $referenceGaps = (int) $sqlite->query('SELECT COUNT(*) FROM (SELECT way_id FROM way_refs GROUP BY way_id HAVING MIN(sequence_index)<>0 OR MAX(sequence_index)+1<>COUNT(*))')->fetchColumn();
        $requiredMismatch = (int) $sqlite->query('SELECT COUNT(*) FROM (SELECT node_id FROM way_refs EXCEPT SELECT id FROM required_nodes)')->fetchColumn()
            + (int) $sqlite->query('SELECT COUNT(*) FROM (SELECT id FROM required_nodes EXCEPT SELECT node_id FROM way_refs)')->fetchColumn();
        $resolutionMismatch = (int) $sqlite->query('SELECT COUNT(*) FROM (SELECT id FROM required_nodes EXCEPT SELECT id FROM resolved_node_ids)')->fetchColumn()
            + (int) $sqlite->query('SELECT COUNT(*) FROM (SELECT id FROM resolved_node_ids EXCEPT SELECT id FROM required_nodes)')->fetchColumn();
        if ($missingReferences !== 0 || $referenceGaps !== 0 || $requiredMismatch !== 0 || $resolutionMismatch !== 0) {
            throw new RuntimeException('Offline source index contains missing nodes, incomplete way references, or inconsistent required-node rows.');
        }
        $sourceNodeMismatch = (int) $sqlite->query("SELECT COUNT(*) FROM graph_nodes AS graph LEFT JOIN source_nodes AS source ON source.id=graph.osm_node_id WHERE source.id IS NULL OR graph.latitude<>source.latitude OR graph.longitude<>source.longitude OR graph.in_scope<>source.in_scope OR graph.walkable<>source.walkable OR COALESCE(graph.barrier, '')<>COALESCE(source.barrier, '') OR COALESCE(graph.crossing, '')<>COALESCE(source.crossing, '')")->fetchColumn();
        if ($sourceNodeMismatch !== 0) {
            throw new RuntimeException('Offline graph nodes do not match their source coordinates and access tags.');
        }
        if ($sqlite->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('Offline graph contains a broken SQLite endpoint relationship.');
        }
        $scope = $metadata['source_bounds'] ?? null;
        if (! is_array($scope)) {
            throw new RuntimeException('Offline graph coverage bounds are missing.');
        }
        $invalidNodes = $sqlite->prepare('SELECT COUNT(*) FROM graph_nodes WHERE latitude < -90 OR latitude > 90 OR longitude < -180 OR longitude > 180 OR in_scope <> 1 OR latitude < ? OR latitude > ? OR longitude < ? OR longitude > ?');
        $invalidNodes->execute([(float) $scope['min_latitude'], (float) $scope['max_latitude'], (float) $scope['min_longitude'], (float) $scope['max_longitude']]);
        if ((int) $invalidNodes->fetchColumn() !== 0) {
            throw new RuntimeException('Offline graph has invalid or out-of-scope node coordinates.');
        }

        $edgeRows = $sqlite->query('SELECT edges.*, from_nodes.latitude AS from_lat, from_nodes.longitude AS from_lon, from_nodes.walkable AS from_walkable, to_nodes.latitude AS to_lat, to_nodes.longitude AS to_lon, to_nodes.walkable AS to_walkable, ways.tags AS way_tags FROM graph_edges AS edges LEFT JOIN graph_nodes AS from_nodes ON from_nodes.osm_node_id=edges.from_node_id LEFT JOIN graph_nodes AS to_nodes ON to_nodes.osm_node_id=edges.to_node_id LEFT JOIN source_ways AS ways ON ways.id=edges.osm_way_id ORDER BY edges.osm_way_id, edges.segment_index');
        while ($edge = $edgeRows->fetch(PDO::FETCH_ASSOC)) {
            if ($edge['from_lat'] === null || $edge['to_lat'] === null || $edge['way_tags'] === null
                || (int) $edge['from_walkable'] !== 1 || (int) $edge['to_walkable'] !== 1
                || (int) $edge['from_node_id'] === (int) $edge['to_node_id']
                || (float) $edge['length_meters'] < 0.1 || (float) $edge['length_meters'] > 10_000) {
                throw new RuntimeException('Offline graph contains an invalid edge or missing endpoint.');
            }
            $tags = PedestrianOsmPolicy::tags(json_decode((string) $edge['way_tags'], true, 32, JSON_THROW_ON_ERROR));
            if (! PedestrianOsmPolicy::walkableWay($tags)) {
                throw new RuntimeException('Offline graph contains an edge from a restricted pedestrian way.');
            }
            [$forward, $backward] = PedestrianOsmPolicy::directions($tags);
            $references = $sqlite->prepare('SELECT node_id FROM way_refs WHERE way_id=? AND sequence_index IN (?, ?) ORDER BY sequence_index');
            $segment = (int) $edge['segment_index'];
            $references->execute([(int) $edge['osm_way_id'], $segment, $segment + 1]);
            $ordered = array_map('intval', $references->fetchAll(PDO::FETCH_COLUMN));
            if (count($ordered) !== 2) {
                throw new RuntimeException('Offline graph edge does not match its source way references.');
            }
            $expectedFrom = $forward ? $ordered[0] : $ordered[1];
            $expectedTo = $forward ? $ordered[1] : $ordered[0];
            if ((! $forward && ! $backward)
                || (int) $edge['from_node_id'] !== $expectedFrom
                || (int) $edge['to_node_id'] !== $expectedTo
                || (int) $edge['bidirectional'] !== ($forward && $backward ? 1 : 0)) {
                throw new RuntimeException('Offline graph edge direction conflicts with its OSM way restrictions.');
            }
            $expectedWkt = sprintf('LINESTRING(%.7F %.7F,%.7F %.7F)', (float) $edge['from_lon'], (float) $edge['from_lat'], (float) $edge['to_lon'], (float) $edge['to_lat']);
            if (! hash_equals($expectedWkt, (string) $edge['geometry_wkt'])) {
                throw new RuntimeException('Offline graph geometry does not match its edge endpoints in EPSG:4326.');
            }
        }

        $components = OfflinePedestrianGraphTopology::componentCount($sqlite);
        if ($components !== (int) ($metadata['component_count'] ?? -1)) {
            throw new RuntimeException('Offline graph connected-component count does not match its checkpoint.');
        }
        if (! hash_equals((string) ($metadata['graph_content_sha256'] ?? ''), OfflinePedestrianGraphTopology::contentHash($sqlite))) {
            throw new RuntimeException('Offline graph content fingerprint does not match its rows.');
        }

        $routingReady = (bool) ($metadata['routing_complete'] ?? false)
            && (int) ($metadata['relation_restrictions'] ?? 0) === 0;
        if ((bool) ($metadata['routing_complete'] ?? false) !== ((int) ($metadata['relation_restrictions'] ?? 0) === 0)) {
            throw new RuntimeException('Offline graph routing completeness marker conflicts with source restriction relations.');
        }

        return [
            'status' => $routingReady ? 'PASS' : 'WARN',
            'artifact_ready' => true,
            'routing_ready' => $routingReady,
            'source_sha256' => $storedHash,
            'nodes' => $nodes,
            'edges' => $edges,
            'components' => $components,
            'relation_restrictions' => (int) ($metadata['relation_restrictions'] ?? 0),
        ];
    }

    private function metadata(PDO $sqlite): array
    {
        try {
            $metadata = [];
            foreach ($sqlite->query('SELECT key, value FROM metadata') as $row) {
                $metadata[(string) $row['key']] = json_decode((string) $row['value'], true, 32, JSON_THROW_ON_ERROR);
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException('File is not a readable Biyahe offline graph SQLite artifact.', 0, $exception);
        }

        return $metadata;
    }
}
