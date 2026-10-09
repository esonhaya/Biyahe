<?php

namespace App\Services\Routing;

use PDO;
use RuntimeException;

/** Adds source-reference evidence to graph quality counts without altering the artifact. */
final class OfflinePedestrianGraphEvidenceAudit
{
    /** @return array<string, mixed> */
    public function audit(string $graphPath, array $componentSummary): array
    {
        $sqlite = new PDO('sqlite:file:'.$graphPath.'?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec('PRAGMA temp_store=FILE');
        $sqlite->exec('PRAGMA cache_size=-8192');
        $metadata = [];
        foreach ($sqlite->query('SELECT key, value FROM metadata') as $row) {
            $metadata[(string) $row['key']] = json_decode((string) $row['value'], true, 32, JSON_THROW_ON_ERROR);
        }
        $bounds = $metadata['source_bounds'] ?? null;
        if (! is_array($bounds)) {
            throw new RuntimeException('Graph quality review has no source coverage bounds.');
        }

        $longEdges = $this->longEdges($sqlite);
        $componentSamples = $this->componentSamples($sqlite, $componentSummary['component_sizes'] ?? []);
        $boundaryCount = $this->boundaryCount($sqlite, $bounds);
        $crossingCount = (int) $sqlite->query("SELECT COUNT(*) FROM graph_nodes WHERE crossing IS NOT NULL AND crossing<>''")->fetchColumn();
        $clippedWayCount = (int) $sqlite->query(
            'SELECT COUNT(*) FROM (SELECT refs.way_id FROM way_refs AS refs JOIN source_nodes AS nodes ON nodes.id=refs.node_id '
            .'GROUP BY refs.way_id HAVING SUM(CASE WHEN nodes.in_scope=1 THEN 1 ELSE 0 END)>0 AND SUM(CASE WHEN nodes.in_scope=0 THEN 1 ELSE 0 END)>0)'
        )->fetchColumn();
        $outsideRequiredNodes = (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes AS nodes JOIN required_nodes AS refs ON refs.id=nodes.id WHERE nodes.in_scope=0')->fetchColumn();

        return [
            'format' => 'biyahe-cebu-graph-quality-evidence-v1',
            'source_sha256' => (string) ($metadata['source_sha256'] ?? ''),
            'graph_fingerprint' => (string) ($metadata['graph_content_sha256'] ?? ''),
            'counts' => [
                'walkable_components' => count($componentSummary['component_sizes'] ?? []),
                'largest_walkable_component_nodes' => (int) ($componentSummary['largest_component_nodes'] ?? 0),
                'long_edges_over_250m' => count($longEdges),
                'suspicious_long_edges' => count(array_filter($longEdges, static fn (array $edge): bool => $edge['suspicious'])),
                'duplicate_way_segment_identities' => (int) $sqlite->query('SELECT COUNT(*) FROM (SELECT osm_way_id, segment_index FROM graph_edges GROUP BY osm_way_id, segment_index HAVING COUNT(*)>1)')->fetchColumn(),
                'edges_touching_blocked_nodes' => (int) $sqlite->query('SELECT COUNT(*) FROM graph_edges AS edges JOIN graph_nodes AS from_nodes ON from_nodes.osm_node_id=edges.from_node_id JOIN graph_nodes AS to_nodes ON to_nodes.osm_node_id=edges.to_node_id WHERE from_nodes.walkable=0 OR to_nodes.walkable=0')->fetchColumn(),
                'boundary_nodes' => $boundaryCount,
                'crossing_tagged_nodes' => $crossingCount,
                'clipped_ways' => $clippedWayCount,
                'outside_scope_required_nodes' => $outsideRequiredNodes,
                'artificial_connectors_added' => false,
            ],
            'long_edge_review' => [
                'classification_policy' => 'Compare each >250 m graph segment with the adjacent ordered source way references, original endpoint coordinates, direction metadata, and stored endpoint geometry. A source-backed long segment is not called safe without field evidence.',
                'edges' => $longEdges,
            ],
            'walkable_component_samples' => $componentSamples,
            'boundary_node_samples' => $this->boundarySamples($sqlite, $bounds),
            'crossing_node_samples' => $this->crossingSamples($sqlite),
            'clipped_way_samples' => $this->clippedWaySamples($sqlite),
            'interpretation' => [
                'Source-backed geometry establishes that a long segment matches its source OSM way references; it does not establish pedestrian safety or current passability.',
                '432 disconnected walkable components are retained as source topology; no artificial connection is introduced.',
                'Boundary clipping and mapped crossing tags are review signals. A crossing tag or geometric intersection does not create connectivity.',
            ],
            'field_review_required' => count($longEdges) > 0
                || count($componentSummary['component_sizes'] ?? []) > 1
                || $boundaryCount > 0,
        ];
    }

    private function longEdges(PDO $sqlite): array
    {
        $edgeStatement = $sqlite->query(
            'SELECT edges.osm_way_id, edges.segment_index, edges.from_node_id, edges.to_node_id, edges.bidirectional, edges.length_meters, '
            .'edges.highway, edges.properties, edges.geometry_wkt, from_nodes.latitude AS from_latitude, from_nodes.longitude AS from_longitude, '
            .'to_nodes.latitude AS to_latitude, to_nodes.longitude AS to_longitude, ways.tags '
            .'FROM graph_edges AS edges JOIN graph_nodes AS from_nodes ON from_nodes.osm_node_id=edges.from_node_id '
            .'JOIN graph_nodes AS to_nodes ON to_nodes.osm_node_id=edges.to_node_id '
            .'JOIN source_ways AS ways ON ways.id=edges.osm_way_id WHERE edges.length_meters>250 '
            .'ORDER BY edges.length_meters DESC, edges.osm_way_id, edges.segment_index'
        );
        $referenceStatement = $sqlite->prepare('SELECT sequence_index, node_id FROM way_refs WHERE way_id=? AND sequence_index IN (?, ?) ORDER BY sequence_index');
        $longEdges = [];
        while ($edge = $edgeStatement->fetch(PDO::FETCH_ASSOC)) {
            $segment = (int) $edge['segment_index'];
            $referenceStatement->execute([(int) $edge['osm_way_id'], $segment, $segment + 1]);
            $references = $referenceStatement->fetchAll(PDO::FETCH_ASSOC);
            $referenceStatement->closeCursor();
            $tags = PedestrianOsmPolicy::tags(json_decode((string) $edge['tags'], true, 32, JSON_THROW_ON_ERROR));
            [$forward, $backward] = PedestrianOsmPolicy::directions($tags);
            $expectedFrom = count($references) === 2 ? (int) $references[$forward ? 0 : 1]['node_id'] : null;
            $expectedTo = count($references) === 2 ? (int) $references[$forward ? 1 : 0]['node_id'] : null;
            $sequentialReferencesMatch = count($references) === 2
                && (int) $references[0]['sequence_index'] === $segment
                && (int) $references[1]['sequence_index'] === $segment + 1;
            $directionMatches = $sequentialReferencesMatch
                && ($forward || $backward)
                && (int) $edge['from_node_id'] === $expectedFrom
                && (int) $edge['to_node_id'] === $expectedTo
                && (int) $edge['bidirectional'] === (int) ($forward && $backward);
            $expectedWkt = sprintf('LINESTRING(%.7F %.7F,%.7F %.7F)', (float) $edge['from_longitude'], (float) $edge['from_latitude'], (float) $edge['to_longitude'], (float) $edge['to_latitude']);
            $geometryMatches = hash_equals($expectedWkt, (string) $edge['geometry_wkt']);
            $endpointDistance = $this->distanceMeters(
                (float) $edge['from_latitude'], (float) $edge['from_longitude'],
                (float) $edge['to_latitude'], (float) $edge['to_longitude'],
            );
            $distanceMatches = abs($endpointDistance - (float) $edge['length_meters']) <= 0.1;
            $evidenceComplete = $sequentialReferencesMatch && $directionMatches && $geometryMatches && $distanceMatches;
            $longEdges[] = [
                'osm_way_id' => (int) $edge['osm_way_id'],
                'segment_index' => $segment,
                'from_node_id' => (int) $edge['from_node_id'],
                'to_node_id' => (int) $edge['to_node_id'],
                'from_coordinate' => [(float) $edge['from_longitude'], (float) $edge['from_latitude']],
                'to_coordinate' => [(float) $edge['to_longitude'], (float) $edge['to_latitude']],
                'length_meters' => round((float) $edge['length_meters'], 2),
                'source_endpoint_distance_meters' => round($endpointDistance, 2),
                'highway' => (string) $edge['highway'],
                'source_tags' => array_intersect_key($tags, array_flip(['foot', 'access', 'sidewalk', 'oneway:foot', 'foot:forward', 'foot:backward', 'bridge', 'tunnel', 'layer', 'surface', 'lit'])),
                'source_references' => array_map(static fn (array $reference): array => ['sequence_index' => (int) $reference['sequence_index'], 'node_id' => (int) $reference['node_id']], $references),
                'checks' => [
                    'consecutive_source_way_references' => $sequentialReferencesMatch,
                    'source_direction_matches' => $directionMatches,
                    'endpoint_geometry_matches' => $geometryMatches,
                    'source_endpoint_distance_matches' => $distanceMatches,
                ],
                'classification' => $evidenceComplete ? 'source_backed_long_segment_requires_field_review' : 'source_processing_mismatch_requires_investigation',
                'suspicious' => ! $evidenceComplete,
                'field_review_required' => true,
            ];
        }
        $edgeStatement->closeCursor();

        return $longEdges;
    }

    private function componentSamples(PDO $sqlite, array $componentSizes): array
    {
        $samples = [];
        arsort($componentSizes, SORT_NUMERIC);
        $statement = $sqlite->prepare('SELECT latitude, longitude FROM graph_nodes WHERE osm_node_id=? AND walkable=1');
        foreach ($componentSizes as $componentId => $size) {
            $statement->execute([(int) $componentId]);
            $coordinate = $statement->fetch(PDO::FETCH_ASSOC);
            $statement->closeCursor();
            $samples[] = [
                'component_id' => (int) $componentId,
                'node_count' => (int) $size,
                'representative_node_id' => (int) $componentId,
                'representative_coordinate' => is_array($coordinate)
                    ? [(float) $coordinate['longitude'], (float) $coordinate['latitude']]
                    : null,
            ];
        }

        return $samples;
    }

    private function boundarySamples(PDO $sqlite, array $bounds): array
    {
        $statement = $sqlite->prepare(
            'SELECT osm_node_id, latitude, longitude, walkable, barrier FROM graph_nodes WHERE '
            .'ABS(latitude-?)<=0.0005 OR ABS(latitude-?)<=0.0005 OR ABS(longitude-?)<=0.0005 OR ABS(longitude-?)<=0.0005 '
            .'ORDER BY osm_node_id LIMIT 12'
        );
        $statement->execute([
            (float) $bounds['min_latitude'], (float) $bounds['max_latitude'],
            (float) $bounds['min_longitude'], (float) $bounds['max_longitude'],
        ]);
        $samples = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $samples[] = [
                'osm_node_id' => (int) $row['osm_node_id'],
                'coordinate' => [(float) $row['longitude'], (float) $row['latitude']],
                'walkable' => (bool) $row['walkable'],
                'barrier' => $row['barrier'],
            ];
        }

        return $samples;
    }

    private function crossingSamples(PDO $sqlite): array
    {
        $rows = $sqlite->query("SELECT osm_node_id, latitude, longitude, walkable, crossing FROM graph_nodes WHERE crossing IS NOT NULL AND crossing<>'' ORDER BY osm_node_id LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'osm_node_id' => (int) $row['osm_node_id'],
            'coordinate' => [(float) $row['longitude'], (float) $row['latitude']],
            'walkable' => (bool) $row['walkable'],
            'crossing_tags' => (string) $row['crossing'],
            'creates_connectivity' => false,
        ], $rows);
    }

    private function clippedWaySamples(PDO $sqlite): array
    {
        $rows = $sqlite->query(
            'SELECT refs.way_id, COUNT(*) AS reference_count, '
            .'SUM(CASE WHEN nodes.in_scope=1 THEN 1 ELSE 0 END) AS in_scope_references, '
            .'SUM(CASE WHEN nodes.in_scope=0 THEN 1 ELSE 0 END) AS out_of_scope_references '
            .'FROM way_refs AS refs JOIN source_nodes AS nodes ON nodes.id=refs.node_id '
            .'GROUP BY refs.way_id HAVING in_scope_references>0 AND out_of_scope_references>0 '
            .'ORDER BY refs.way_id LIMIT 12'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'osm_way_id' => (int) $row['way_id'],
            'reference_count' => (int) $row['reference_count'],
            'in_scope_references' => (int) $row['in_scope_references'],
            'out_of_scope_references' => (int) $row['out_of_scope_references'],
            'clipping_effect' => 'retained complete ordered references; graph edges exist only for in-scope adjacent pairs',
        ], $rows);
    }

    private function boundaryCount(PDO $sqlite, array $bounds): int
    {
        $statement = $sqlite->prepare(
            'SELECT COUNT(*) FROM graph_nodes WHERE ABS(latitude-?)<=0.0005 OR ABS(latitude-?)<=0.0005 '
            .'OR ABS(longitude-?)<=0.0005 OR ABS(longitude-?)<=0.0005'
        );
        $statement->execute([
            (float) $bounds['min_latitude'], (float) $bounds['max_latitude'],
            (float) $bounds['min_longitude'], (float) $bounds['max_longitude'],
        ]);

        return (int) $statement->fetchColumn();
    }

    private function distanceMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadius = 6_371_000;
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitudeDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    }
}
