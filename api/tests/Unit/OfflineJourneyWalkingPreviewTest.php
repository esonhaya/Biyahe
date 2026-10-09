<?php

namespace Tests\Unit;

use App\Services\Routing\OfflineJourneyWalkingPreview;
use App\Services\Routing\OfflineWalkingPreviewException;
use PDO;
use Tests\Support\OfflinePedestrianGraphFixture;
use Tests\TestCase;

final class OfflineJourneyWalkingPreviewTest extends TestCase
{
    public function test_preview_reuses_the_source_router_and_changes_only_walking_evidence(): void
    {
        $graph = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.87, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
        ], [[100, 0, 1, 2, 1, 10.0]]);
        $settings = $this->settings($graph);

        try {
            $journey = $this->journey();
            $rideBefore = $journey['legs'][1];
            $rankingBefore = $journey['ranking'];
            $result = $this->previewer($settings)->preview($journey);
            $walk = $result['journey']['legs'][0];

            $this->assertSame('DEVELOPMENT_ONLY', $result['preview']['mode']);
            $this->assertSame('ROUTE_FOUND', $walk['routing_status']);
            $this->assertSame('PEDESTRIAN_NETWORK', $walk['distance_type']);
            $this->assertSame('OSM_NETWORK_WITHIN_SNAP_TOLERANCE', $walk['distance_provenance']);
            $this->assertSame('OPENSTREETMAP_GRAPH_EDGE_COORDINATES', $walk['geometry_provenance']);
            $this->assertSame(10.0, $walk['network_distance_meters']);
            $this->assertSame(0.0, $walk['approximate_connector_distance_meters']);
            $this->assertSame('LineString', $walk['geometry']['type']);
            $this->assertSame(2, count($walk['geometry']['coordinates']));
            $this->assertFalse($result['preview']['field_safety_verified']);
            $this->assertSame(3, $result['preview']['graph_schema_version']);
            $this->assertSame(0, $result['preview']['restriction_inventory']['pedestrian_applicable']);
            $this->assertSame($rideBefore, $result['journey']['legs'][1]);
            $this->assertSame($rankingBefore, $result['journey']['ranking']);
        } finally {
            OfflinePedestrianGraphFixture::remove($graph);
            @unlink($settings['coverage_report_path']);
        }
    }

    public function test_disconnected_preview_has_a_reason_and_no_geometry_connector(): void
    {
        $graph = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.87, 1, null, null],
            [2, 10.3, 123.871, 1, null, null],
        ], []);
        $settings = $this->settings($graph);

        try {
            $journey = $this->journey();
            $journey['legs'][0]['destination']['location']['lng'] = 123.871;
            $journey['legs'][0]['geometry'] = ['type' => 'LineString', 'coordinates' => [[123.87, 10.3], [123.871, 10.3]]];
            $result = $this->previewer($settings)->preview($journey);
            $walk = $result['journey']['legs'][0];

            $this->assertSame('ROUTE_UNAVAILABLE', $walk['routing_status']);
            $this->assertSame('component_disconnected', $walk['offline_walking_preview']['reason_code']);
            $this->assertSame('UNAVAILABLE', $walk['distance_type']);
            $this->assertFalse($walk['distance_is_estimate']);
            $this->assertNull($walk['geometry']);
            $this->assertSame('PARTIAL', $result['preview']['status']);
        } finally {
            OfflinePedestrianGraphFixture::remove($graph);
            @unlink($settings['coverage_report_path']);
        }
    }

    public function test_source_mismatch_fails_closed_before_any_route_is_claimed(): void
    {
        $graph = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.87, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
        ], [[100, 0, 1, 2, 1, 10.0]]);
        $settings = $this->settings($graph);
        $settings['graph_sha256'] = str_repeat('b', 64);

        try {
            try {
                $this->previewer($settings)->preview($this->journey());
                $this->fail('A graph evidence mismatch must not produce route geometry.');
            } catch (OfflineWalkingPreviewException $exception) {
                $this->assertSame('source_mismatch', $exception->reasonCode);
            }
        } finally {
            OfflinePedestrianGraphFixture::remove($graph);
            @unlink($settings['coverage_report_path']);
        }
    }

    public function test_missing_graph_fails_closed_with_a_reason_code(): void
    {
        $graph = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.87, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
        ], [[100, 0, 1, 2, 1, 10.0]]);
        $settings = $this->settings($graph);
        $settings['graph_path'] = $graph.'.missing';

        try {
            try {
                $this->previewer($settings)->preview($this->journey());
                $this->fail('A missing graph must not produce route geometry.');
            } catch (OfflineWalkingPreviewException $exception) {
                $this->assertSame('graph_unavailable', $exception->reasonCode);
            }
        } finally {
            OfflinePedestrianGraphFixture::remove($graph);
            @unlink($settings['coverage_report_path']);
        }
    }

    public function test_unsupported_sqlite_schema_is_rejected_even_when_its_hash_is_pinned(): void
    {
        $graph = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.87, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
        ], [[100, 0, 1, 2, 1, 10.0]]);
        $sqlite = new PDO('sqlite:'.$graph);
        $sqlite->exec("UPDATE metadata SET value='99' WHERE key='schema_version'");
        $settings = $this->settings($graph);

        try {
            $this->previewer($settings)->preview($this->journey());
            $this->fail('A pinned file hash must not bypass the graph schema contract.');
        } catch (OfflineWalkingPreviewException $exception) {
            $this->assertSame('unsupported_routing_capability', $exception->reasonCode);
        } finally {
            OfflinePedestrianGraphFixture::remove($graph);
            @unlink($settings['coverage_report_path']);
        }
    }

    public function test_malformed_walking_coordinates_return_invalid_endpoint_without_fallback_geometry(): void
    {
        $graph = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.87, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
        ], [[100, 0, 1, 2, 1, 10.0]]);
        $settings = $this->settings($graph);
        $journey = $this->journey();
        $journey['legs'][0]['origin'] = 'malformed';

        try {
            $result = $this->previewer($settings)->preview($journey);
            $walk = $result['journey']['legs'][0];
            $this->assertSame('invalid_endpoint', $walk['offline_walking_preview']['reason_code']);
            $this->assertNull($walk['geometry']);
            $this->assertNull($walk['distance_meters']);
        } finally {
            OfflinePedestrianGraphFixture::remove($graph);
            @unlink($settings['coverage_report_path']);
        }
    }

    public function test_unsnappable_endpoint_is_a_coverage_gap_without_a_straight_line(): void
    {
        $graph = OfflinePedestrianGraphFixture::create([
            [1, 10.3, 123.87, 1, null, null],
            [2, 10.3, 123.8701, 1, null, null],
        ], [[100, 0, 1, 2, 1, 10.0]]);
        $settings = $this->settings($graph);
        $journey = $this->journey();
        $journey['legs'][0]['destination']['location'] = ['lat' => 10.31, 'lng' => 123.88];

        try {
            $result = $this->previewer($settings)->preview($journey);
            $walk = $result['journey']['legs'][0];

            $this->assertSame('snap_unavailable', $walk['offline_walking_preview']['reason_code']);
            $this->assertSame('GRAPH_COVERAGE_GAP', $walk['access_classification']);
            $this->assertNull($walk['geometry']);
            $this->assertNull($walk['distance_meters']);
        } finally {
            OfflinePedestrianGraphFixture::remove($graph);
            @unlink($settings['coverage_report_path']);
        }
    }

    public function test_preview_refuses_resource_headroom_below_the_existing_swap_floor(): void
    {
        $previewer = new OfflineJourneyWalkingPreview([], static fn (): array => [
            'memavailable_kib' => 600_000,
            'swapfree_kib' => 131_071,
        ]);

        try {
            $previewer->preview($this->journey());
            $this->fail('Resource pressure must stop local graph routing.');
        } catch (OfflineWalkingPreviewException $exception) {
            $this->assertSame('resource_budget_exceeded', $exception->reasonCode);
        }
    }

    public function test_empty_journey_is_rejected_instead_of_returning_a_no_walking_preview(): void
    {
        $previewer = new OfflineJourneyWalkingPreview([], static fn (): array => [
            'memavailable_kib' => 600_000,
            'swapfree_kib' => 200_000,
        ]);

        try {
            $previewer->preview(['id' => 'empty-journey', 'legs' => []]);
            $this->fail('A journey without legs is not a valid preview input.');
        } catch (OfflineWalkingPreviewException $exception) {
            $this->assertSame('invalid_journey', $exception->reasonCode);
        }
    }

    private function settings(string $graph): array
    {
        $reportPath = tempnam(sys_get_temp_dir(), 'offline-walk-evidence-');
        $graphSha = hash_file('sha256', $graph);
        $sqlite = new PDO('sqlite:'.$graph);
        $nodeCount = (int) $sqlite->query('SELECT COUNT(*) FROM graph_nodes')->fetchColumn();
        $edgeCount = (int) $sqlite->query('SELECT COUNT(*) FROM graph_edges')->fetchColumn();
        unset($sqlite);
        $report = [
            'format' => 'biyahe-cebu-offline-coverage-v1',
            'complete' => true,
            'source' => ['graph_source_sha256' => OfflinePedestrianGraphFixture::SOURCE_SHA256],
            'snap_policy' => ['maximum_distance_meters' => 25, 'virtual_edge_anchors' => false],
            'graph_validation' => [
                'artifact_ready' => true,
                'routing_ready' => true,
                'nodes' => $nodeCount,
                'edges' => $edgeCount,
                'restriction_relations' => 0,
                'artifact_sha256' => $graphSha,
                'graph_fingerprint' => str_repeat('c', 64),
                'restriction_inventory_complete' => true,
                'restriction_counts' => ['total' => 0, 'pedestrian_applicable' => 0, 'ambiguous' => 0, 'unsupported_applicable' => 0],
                'routing_capabilities' => [
                    'complete_restriction_inventory' => true,
                    'routing_ready' => true,
                    'turn_restrictions_needed' => false,
                    'conditional_turn_restrictions_evaluated' => false,
                    'state_aware_search' => false,
                ],
            ],
        ];
        file_put_contents($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

        return [
            'graph_path' => $graph,
            'graph_format' => 'biyahe-pedestrian-sqlite-v3',
            'graph_schema_version' => 3,
            'graph_sha256' => $graphSha,
            'source_sha256' => OfflinePedestrianGraphFixture::SOURCE_SHA256,
            'graph_fingerprint' => str_repeat('c', 64),
            'graph_nodes' => $nodeCount,
            'graph_edges' => $edgeCount,
            'restriction_relations' => 0,
            'coverage_report_path' => $reportPath,
            'coverage_report_sha256' => hash_file('sha256', $reportPath),
            'maximum_snap_distance_meters' => 25,
            'maximum_route_distance_meters' => 2_000,
            'maximum_walking_legs' => 5,
            'maximum_geometry_points' => 2048,
            'maximum_request_seconds' => 8,
            'maximum_request_bytes' => 2_000_000,
            'maximum_response_bytes' => 2_000_000,
        ];
    }

    private function journey(): array
    {
        return [
            'id' => 'fixture-journey',
            'ranking' => ['walking_distance_meters' => 10.0, 'reasons' => ['fixture reason']],
            'legs' => [
                [
                    'type' => 'WALK_ACCESS',
                    'origin' => ['location' => ['lat' => 10.3, 'lng' => 123.87]],
                    'destination' => ['location' => ['lat' => 10.3, 'lng' => 123.8701]],
                    'geometry' => null,
                    'warnings' => [],
                ],
                ['type' => 'RIDE', 'route' => ['code' => 'Fixture'], 'geometry' => null],
            ],
        ];
    }

    private function previewer(array $settings, ?array $sample = null): OfflineJourneyWalkingPreview
    {
        $sample ??= ['memavailable_kib' => 1_000_000, 'swapfree_kib' => 500_000];

        return new OfflineJourneyWalkingPreview($settings, static fn (): array => $sample);
    }
}
