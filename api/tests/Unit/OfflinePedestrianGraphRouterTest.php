<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianGraphRouter;
use PDO;
use Tests\TestCase;

final class OfflinePedestrianGraphRouterTest extends TestCase
{
    public function test_routes_use_the_shortest_directed_source_path_and_estimated_duration_label(): void
    {
        $graph = $this->graph([
            [1, 10.3, 123.8700, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
            [3, 10.3, 123.8702, 1, null, null],
            [4, 10.3001, 123.8701, 1, null, null],
        ], [
            [100, 0, 1, 2, 0, 10.0],
            [100, 1, 2, 3, 0, 10.0],
            [200, 0, 1, 4, 0, 30.0],
            [200, 1, 4, 3, 0, 30.0],
        ]);

        try {
            $router = $this->router($graph['path']);
            $route = $router->route(['lat' => 10.3, 'lng' => 123.87], ['lat' => 10.3, 'lng' => 123.8702], 100);
            $reverse = $router->route(['lat' => 10.3, 'lng' => 123.8702], ['lat' => 10.3, 'lng' => 123.87], 100);

            $this->assertSame('ROUTE_FOUND', $route['status']);
            $this->assertSame([1, 2, 3], $route['node_ids']);
            $this->assertSame(20.0, $route['distance_meters']);
            $this->assertSame('estimate_only', $route['duration_label']);
            $this->assertNotNull($route['estimated_duration_seconds']);
            $this->assertSame('path_unavailable', $reverse['reason_code']);
        } finally {
            $this->removeGraph($graph['path']);
        }
    }

    public function test_rejects_snaps_outside_the_bounded_radius_and_mapped_barrier_connectors(): void
    {
        $graph = $this->graph([
            [1, 10.3, 123.8701, 1, null, null],
            [2, 10.3, 123.8702, 1, null, null],
            [3, 10.3, 123.87005, 0, 'fence', null],
        ], [[100, 0, 1, 2, 1, 11.0]]);

        try {
            $router = $this->router($graph['path']);
            $blocked = $router->snap(['lat' => 10.3, 'lng' => 123.86998]);
            $distant = $router->snap(['lat' => 10.3, 'lng' => 123.8710]);

            $this->assertSame('snap_unavailable', $blocked['reason_code']);
            $this->assertSame(true, $blocked['blocked_by_mapped_barrier']);
            $this->assertSame('snap_unavailable', $distant['reason_code']);
        } finally {
            $this->removeGraph($graph['path']);
        }
    }

    public function test_reports_disconnected_components_without_building_a_connector(): void
    {
        $graph = $this->graph([
            [1, 10.3, 123.8700, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
            [3, 10.3, 123.8702, 1, null, null],
            [4, 10.3, 123.8703, 1, null, null],
        ], [
            [100, 0, 1, 2, 1, 11.0],
            [200, 0, 3, 4, 1, 11.0],
        ]);

        try {
            $router = $this->router($graph['path']);
            $route = $router->route(['lat' => 10.3, 'lng' => 123.87], ['lat' => 10.3, 'lng' => 123.8702], 100);

            $this->assertSame('component_disconnected', $route['reason_code']);
            $this->assertNull($route['geometry']);
            $this->assertSame(2, $router->componentSummary()['components']);
        } finally {
            $this->removeGraph($graph['path']);
        }
    }

    public function test_returns_resource_budget_reason_when_local_edge_window_exceeds_limit(): void
    {
        $graph = $this->graph([
            [1, 10.3, 123.8700, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
            [3, 10.3, 123.8702, 1, null, null],
        ], [
            [100, 0, 1, 2, 1, 11.0],
            [100, 1, 2, 3, 1, 11.0],
        ]);

        try {
            $router = $this->router($graph['path'], maximumEdges: 1);
            $route = $router->route(['lat' => 10.3, 'lng' => 123.87], ['lat' => 10.3, 'lng' => 123.8702], 100);

            $this->assertSame('resource_budget_exceeded', $route['reason_code']);
            $this->assertNull($route['estimated_duration_seconds']);
        } finally {
            $this->removeGraph($graph['path']);
        }
    }

    public function test_refuses_invalid_endpoints_zero_distance_routes_source_mismatch_and_unsupported_capability(): void
    {
        $graph = $this->graph([
            [1, 10.3, 123.8700, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
        ], [[100, 0, 1, 2, 1, 11.0]]);

        try {
            $router = $this->router($graph['path']);
            $invalid = $router->snap(['lat' => 91, 'lng' => 123.87]);
            $zero = $router->route(['lat' => 10.3, 'lng' => 123.87], ['lat' => 10.3, 'lng' => 123.87]);
            $mismatch = new OfflinePedestrianGraphRouter($graph['path'], str_repeat('b', 64), $this->validation());
            $unsupported = new OfflinePedestrianGraphRouter($graph['path'], str_repeat('a', 64), [
                ...$this->validation(),
                'routing_ready' => false,
            ]);

            $this->assertSame('invalid_endpoint', $invalid['reason_code']);
            $this->assertSame('invalid_endpoint', $zero['reason_code']);
            $this->assertSame('source_mismatch', $mismatch->snap(['lat' => 10.3, 'lng' => 123.87])['reason_code']);
            $this->assertSame('unsupported_routing_capability', $unsupported->snap(['lat' => 10.3, 'lng' => 123.87])['reason_code']);
        } finally {
            $this->removeGraph($graph['path']);
        }
    }

    public function test_repeated_queries_are_deterministic_and_do_not_change_the_graph_file(): void
    {
        $graph = $this->graph([
            [1, 10.3, 123.8700, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
            [3, 10.3, 123.8702, 1, null, null],
            [4, 10.3001, 123.8701, 1, null, null],
        ], [
            [100, 0, 1, 2, 1, 10.0],
            [100, 1, 2, 3, 1, 10.0],
            [200, 0, 1, 4, 1, 14.0],
            [200, 1, 4, 3, 1, 14.0],
        ]);

        try {
            $hashBefore = hash_file('sha256', $graph['path']);
            $router = $this->router($graph['path']);
            $first = $router->route(['lat' => 10.3, 'lng' => 123.87], ['lat' => 10.3, 'lng' => 123.8702], 100);
            $second = $router->route(['lat' => 10.3, 'lng' => 123.87], ['lat' => 10.3, 'lng' => 123.8702], 100);

            $this->assertSame($first['node_ids'], $second['node_ids']);
            $this->assertSame($first['geometry'], $second['geometry']);
            $this->assertSame($hashBefore, hash_file('sha256', $graph['path']));
        } finally {
            $this->removeGraph($graph['path']);
        }
    }

    private function graph(array $nodes, array $edges): array
    {
        $path = tempnam(sys_get_temp_dir(), 'offline-walk-graph-');
        if ($path === false) {
            $this->fail('Could not allocate an isolated SQLite graph fixture.');
        }
        $sqlite = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $sqlite->exec('CREATE TABLE graph_nodes (osm_node_id INTEGER PRIMARY KEY, latitude REAL NOT NULL, longitude REAL NOT NULL, in_scope INTEGER NOT NULL, walkable INTEGER NOT NULL, barrier TEXT NULL, crossing TEXT NULL)');
        $sqlite->exec('CREATE TABLE graph_edges (osm_way_id INTEGER NOT NULL, segment_index INTEGER NOT NULL, from_node_id INTEGER NOT NULL, to_node_id INTEGER NOT NULL, bidirectional INTEGER NOT NULL, length_meters REAL NOT NULL, highway TEXT NOT NULL, properties TEXT NOT NULL, geometry_wkt TEXT NOT NULL, PRIMARY KEY (osm_way_id,segment_index))');
        $metadata = [
            'source_sha256' => str_repeat('a', 64),
            'state' => 'COMPLETE',
            'input_complete' => true,
            'source_bounds' => ['min_latitude' => 10.2, 'max_latitude' => 10.4, 'min_longitude' => 123.8, 'max_longitude' => 124.0],
            'graph_content_sha256' => str_repeat('c', 64),
        ];
        $insertMetadata = $sqlite->prepare('INSERT INTO metadata (key, value) VALUES (?, ?)');
        foreach ($metadata as $key => $value) {
            $insertMetadata->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
        }
        $insertNode = $sqlite->prepare('INSERT INTO graph_nodes (osm_node_id, latitude, longitude, in_scope, walkable, barrier, crossing) VALUES (?, ?, ?, 1, ?, ?, ?)');
        foreach ($nodes as $node) {
            $insertNode->execute($node);
        }
        $insertEdge = $sqlite->prepare('INSERT INTO graph_edges (osm_way_id, segment_index, from_node_id, to_node_id, bidirectional, length_meters, highway, properties, geometry_wkt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($edges as $edge) {
            $insertEdge->execute([...$edge, 'footway', '{}', 'LINESTRING(0 0,0 0)']);
        }
        unset($sqlite);

        return ['path' => $path];
    }

    private function router(string $path, int $maximumEdges = 50_000): OfflinePedestrianGraphRouter
    {
        return new OfflinePedestrianGraphRouter($path, str_repeat('a', 64), $this->validation(), 25, $maximumEdges, 20_000);
    }

    private function validation(): array
    {
        return [
            'artifact_ready' => true,
            'routing_ready' => true,
            'source_sha256' => str_repeat('a', 64),
            'routing_capabilities' => [
                'complete_restriction_inventory' => true,
                'routing_ready' => true,
            ],
        ];
    }

    private function removeGraph(string $path): void
    {
        @unlink($path);
    }
}
