<?php

namespace Tests\Unit;

use App\Services\Routing\OfflinePedestrianRouteGeometryContract;
use InvalidArgumentException;
use Tests\TestCase;

final class OfflinePedestrianRouteGeometryContractTest extends TestCase
{
    public function test_accepts_a_source_bound_directed_path_with_separate_snap_connectors(): void
    {
        OfflinePedestrianRouteGeometryContract::assertResult($this->route(), str_repeat('a', 64), str_repeat('c', 64));
        $this->assertTrue(true);
    }

    public function test_rejects_an_edge_whose_direction_does_not_match_the_ordered_nodes(): void
    {
        $route = $this->route();
        $route['edges'][0]['from_node_id'] = 2;
        $route['edges'][0]['to_node_id'] = 1;

        $this->expectException(InvalidArgumentException::class);
        OfflinePedestrianRouteGeometryContract::assertResult($route, str_repeat('a', 64), str_repeat('c', 64));
    }

    public function test_rejects_geometry_that_includes_an_endpoint_connector(): void
    {
        $route = $this->route();
        array_unshift($route['geometry']['coordinates'], [123.86999, 10.3]);
        array_unshift($route['node_ids'], 99);
        array_unshift($route['edges'], ['from_node_id' => 99, 'to_node_id' => 1, 'way_id' => 99, 'segment_index' => 0, 'length_meters' => 1.0]);

        $this->expectException(InvalidArgumentException::class);
        OfflinePedestrianRouteGeometryContract::assertResult($route, str_repeat('a', 64), str_repeat('c', 64));
    }

    public function test_rejects_invalid_coordinates_distance_and_graph_identity(): void
    {
        $route = $this->route();
        $route['geometry']['coordinates'][1][1] = 91;
        try {
            OfflinePedestrianRouteGeometryContract::assertResult($route, str_repeat('a', 64), str_repeat('c', 64));
            $this->fail('Invalid latitude was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $route = $this->route();
        $route['distance_meters'] = 21;
        try {
            OfflinePedestrianRouteGeometryContract::assertResult($route, str_repeat('a', 64), str_repeat('c', 64));
            $this->fail('Mismatched edge distance was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $route = $this->route();
        $route['graph_fingerprint'] = str_repeat('d', 64);
        $this->expectException(InvalidArgumentException::class);
        OfflinePedestrianRouteGeometryContract::assertResult($route, str_repeat('a', 64), str_repeat('c', 64));
    }

    public function test_rejects_geometry_on_failure_and_validates_disconnected_component_evidence(): void
    {
        $route = [
            'status' => 'ROUTE_UNAVAILABLE',
            'reason_code' => 'component_disconnected',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[123.8, 10.3], [123.81, 10.31]]],
            'origin_component' => 7,
            'destination_component' => 8,
        ];
        try {
            OfflinePedestrianRouteGeometryContract::assertResult($route, str_repeat('a', 64), str_repeat('c', 64));
            $this->fail('A failed route with geometry was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $route['geometry'] = null;
        $route['destination_component'] = 7;
        $this->expectException(InvalidArgumentException::class);
        OfflinePedestrianRouteGeometryContract::assertResult($route, str_repeat('a', 64), str_repeat('c', 64));
    }

    private function route(): array
    {
        return [
            'status' => 'ROUTE_FOUND',
            'source' => 'OPENSTREETMAP',
            'source_sha256' => str_repeat('a', 64),
            'graph_fingerprint' => str_repeat('c', 64),
            'field_safety_verified' => false,
            'geometry' => ['type' => 'LineString', 'coordinates' => [[123.87, 10.3], [123.8701, 10.3], [123.8702, 10.3]]],
            'node_ids' => [1, 2, 3],
            'edges' => [
                ['from_node_id' => 1, 'to_node_id' => 2, 'way_id' => 100, 'segment_index' => 0, 'length_meters' => 10.0],
                ['from_node_id' => 2, 'to_node_id' => 3, 'way_id' => 100, 'segment_index' => 1, 'length_meters' => 10.0],
            ],
            'distance_meters' => 20.0,
            'total_distance_with_approximate_snaps_meters' => 25.0,
            'walking_speed_meters_per_second' => 1.2,
            'estimated_duration_seconds' => 21,
            'duration_label' => 'estimate_only',
            'origin_snap' => ['status' => 'SNAPPED', 'node_id' => 1, 'node_longitude' => 123.87, 'node_latitude' => 10.3, 'snap_distance_meters' => 2.0],
            'destination_snap' => ['status' => 'SNAPPED', 'node_id' => 3, 'node_longitude' => 123.8702, 'node_latitude' => 10.3, 'snap_distance_meters' => 3.0],
        ];
    }
}
