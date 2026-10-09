<?php

namespace App\Services\Routing;

use PDO;

/** Disk-backed diagnostics for clipping, disconnected walkable components and long segments. */
final class OfflinePedestrianGraphQualityAudit
{
    private const BOUNDARY_MARGIN_DEGREES = 0.0005;

    /** @return array<string, mixed> */
    public function audit(PDO $sqlite, array $sourceBounds): array
    {
        $sqlite->exec('DROP TABLE IF EXISTS temp.quality_edge_endpoint_ids');
        $sqlite->exec('CREATE TEMP TABLE quality_edge_endpoint_ids (node_id INTEGER PRIMARY KEY)');
        $sqlite->exec('INSERT OR IGNORE INTO quality_edge_endpoint_ids (node_id) SELECT from_node_id FROM graph_edges');
        $sqlite->exec('INSERT OR IGNORE INTO quality_edge_endpoint_ids (node_id) SELECT to_node_id FROM graph_edges');

        $nodeCountStatement = $sqlite->query('SELECT COUNT(*), SUM(CASE WHEN walkable=0 THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN walkable=0 AND endpoints.node_id IS NULL THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN walkable=1 AND endpoints.node_id IS NULL THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN crossing IS NOT NULL AND crossing<>\'\' THEN 1 ELSE 0 END) '
            .'FROM graph_nodes LEFT JOIN quality_edge_endpoint_ids AS endpoints ON endpoints.node_id=graph_nodes.osm_node_id');
        $nodeCounts = $nodeCountStatement->fetch(PDO::FETCH_NUM);
        $nodeCountStatement->closeCursor();
        $longEdgeStatement = $sqlite->query('SELECT osm_way_id, segment_index, length_meters, highway, properties FROM graph_edges WHERE length_meters>250 ORDER BY length_meters DESC, osm_way_id, segment_index');
        $longEdges = $longEdgeStatement->fetchAll(PDO::FETCH_ASSOC);
        $longEdgeStatement->closeCursor();
        $walkableComponents = OfflinePedestrianGraphTopology::componentStats($sqlite, true);
        $outsideNodes = (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes WHERE in_scope=0')->fetchColumn();
        $outsideRequiredNodes = (int) $sqlite->query('SELECT COUNT(*) FROM source_nodes AS nodes JOIN required_nodes AS refs ON refs.id=nodes.id WHERE nodes.in_scope=0')->fetchColumn();
        $clippedWays = (int) $sqlite->query('SELECT COUNT(*) FROM (SELECT refs.way_id FROM way_refs AS refs JOIN source_nodes AS nodes ON nodes.id=refs.node_id GROUP BY refs.way_id HAVING SUM(CASE WHEN nodes.in_scope=1 THEN 1 ELSE 0 END)>0 AND SUM(CASE WHEN nodes.in_scope=0 THEN 1 ELSE 0 END)>0)')->fetchColumn();
        $boundaryStatement = $sqlite->prepare('SELECT COUNT(*) FROM graph_nodes WHERE ABS(latitude-CAST(? AS REAL))<=CAST(? AS REAL) OR ABS(latitude-CAST(? AS REAL))<=CAST(? AS REAL) OR ABS(longitude-CAST(? AS REAL))<=CAST(? AS REAL) OR ABS(longitude-CAST(? AS REAL))<=CAST(? AS REAL)');
        $boundaryStatement->execute([
            (float) $sourceBounds['min_latitude'], self::BOUNDARY_MARGIN_DEGREES,
            (float) $sourceBounds['max_latitude'], self::BOUNDARY_MARGIN_DEGREES,
            (float) $sourceBounds['min_longitude'], self::BOUNDARY_MARGIN_DEGREES,
            (float) $sourceBounds['max_longitude'], self::BOUNDARY_MARGIN_DEGREES,
        ]);
        $boundaryNodes = (int) $boundaryStatement->fetchColumn();
        $boundaryStatement->closeCursor();

        return [
            'graph_components' => (int) $sqlite->query('SELECT value FROM metadata WHERE key=\'component_count\'')->fetchColumn(),
            'walkable_components' => $walkableComponents['components'],
            'largest_walkable_component_nodes' => $walkableComponents['largest_component_nodes'],
            'long_edges_over_250m' => count($longEdges),
            'maximum_edge_meters_over_250m' => $longEdges === [] ? 0.0 : round((float) $longEdges[0]['length_meters'], 2),
            'long_edge_examples' => array_map(static fn (array $edge): array => [
                'osm_way_id' => (int) $edge['osm_way_id'],
                'segment_index' => (int) $edge['segment_index'],
                'length_meters' => round((float) $edge['length_meters'], 2),
                'highway' => (string) $edge['highway'],
                'properties' => json_decode((string) $edge['properties'], true, 32, JSON_THROW_ON_ERROR),
            ], $longEdges),
            'crossing_tagged_nodes' => (int) ($nodeCounts[4] ?? 0),
            'blocked_nodes' => (int) ($nodeCounts[1] ?? 0),
            'isolated_blocked_nodes' => (int) ($nodeCounts[2] ?? 0),
            'isolated_walkable_nodes' => (int) ($nodeCounts[3] ?? 0),
            'outside_scope_resolved_nodes' => $outsideNodes,
            'outside_scope_required_nodes' => $outsideRequiredNodes,
            'ways_with_inside_and_outside_references' => $clippedWays,
            'boundary_nodes_within_0_0005_degrees' => $boundaryNodes,
            'boundary_review_margin_degrees' => self::BOUNDARY_MARGIN_DEGREES,
            'geometry_intersections_are_not_connections' => true,
            'artificial_connectors_added' => false,
            'field_review_required' => count($longEdges) > 0 || $clippedWays > 0 || $boundaryNodes > 0 || $walkableComponents['components'] > 1,
            'scope_bounds' => $sourceBounds,
        ];
    }
}
