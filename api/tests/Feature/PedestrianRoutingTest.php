<?php

namespace Tests\Feature;

use App\Enums\PedestrianRoutePurpose;
use App\Enums\PedestrianRouteStatus;
use App\Models\Place;
use App\Models\Stop;
use App\Services\Routing\LocalPedestrianGraphRouter;
use App\Services\Routing\PedestrianNetworkImporter;
use App\Services\Routing\PedestrianRouteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class PedestrianRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_osm_graph_routes_on_network_and_keeps_disconnected_components_separate(): void
    {
        $path = $this->writeSyntheticExtract();
        try {
            $import = app(PedestrianNetworkImporter::class)->import($path, 'https://synthetic.invalid/cebu-test-extract.json');
            $this->assertFalse($import->alreadyImported);
            $this->assertSame(9, $import->nodes);
            $this->assertSame(5, $import->edges);
            $this->assertSame(3, $import->restrictedElements);
            $this->assertSame(1, DB::table('pedestrian_network_nodes')->where('is_walkable', false)->count());
            $this->assertSame(0, DB::table('field_observations')->count());

            $router = app(LocalPedestrianGraphRouter::class);
            $route = $router->route($this->request(
                ['lat' => 10.3, 'lng' => 123.87],
                ['lat' => 10.3002, 'lng' => 123.87],
            ));
            $this->assertSame(PedestrianRouteStatus::AVAILABLE, $route->status);
            $this->assertGreaterThan(60, $route->totalDistanceMeters());
            $this->assertNotNull($route->geometry);
            $this->assertSame('LineString', $route->geometry['type']);
            $this->assertSame('OPENSTREETMAP', $route->source);
            $this->assertGreaterThan(0, $route->estimatedDurationSeconds);

            $disconnected = $router->route($this->request(
                ['lat' => 10.3, 'lng' => 123.87],
                ['lat' => 10.3004, 'lng' => 123.87],
            ));
            $this->assertSame(PedestrianRouteStatus::NO_ROUTE, $disconnected->status);
            $this->assertNull($disconnected->geometry);

            $metrics = $router->metrics();
            $this->assertSame(2, $metrics['routes_computed']);
            $this->assertSame(1, $metrics['routes_available']);
            $this->assertSame(0, $metrics['cache_hits']);
        } finally {
            @unlink($path);
        }
    }

    public function test_network_router_respects_foot_direction_and_bounds_and_caches_requests(): void
    {
        $path = $this->writeSyntheticExtract();
        try {
            app(PedestrianNetworkImporter::class)->import($path, 'https://synthetic.invalid/cebu-test-extract.json');
            $router = app(LocalPedestrianGraphRouter::class);

            $backwards = $router->route($this->request(
                ['lat' => 10.3006, 'lng' => 123.8702],
                ['lat' => 10.3006, 'lng' => 123.87],
            ));
            $this->assertSame(PedestrianRouteStatus::NO_ROUTE, $backwards->status);

            $limited = $router->route($this->request(
                ['lat' => 10.3, 'lng' => 123.87],
                ['lat' => 10.3002, 'lng' => 123.87],
                20,
            ));
            $this->assertSame(PedestrianRouteStatus::DISTANCE_LIMIT, $limited->status);

            $request = $this->request(['lat' => 10.3, 'lng' => 123.87], ['lat' => 10.3002, 'lng' => 123.87]);
            $router->route($request);
            $router->route($request);
            $this->assertSame(3, $router->metrics()['routes_computed']);
            $this->assertSame(1, $router->metrics()['cache_hits']);
        } finally {
            @unlink($path);
        }
    }

    public function test_intersecting_geometries_without_a_shared_osm_node_do_not_connect(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'synthetic-crossing-network-');
        if ($path === false) {
            $this->fail('Could not create temporary synthetic crossing fixture.');
        }
        $elements = [
            ['type' => 'node', 'id' => 1, 'lat' => 10.3, 'lon' => 123.87, 'tags' => []],
            ['type' => 'node', 'id' => 2, 'lat' => 10.3002, 'lon' => 123.8702, 'tags' => []],
            ['type' => 'node', 'id' => 3, 'lat' => 10.3, 'lon' => 123.8702, 'tags' => []],
            ['type' => 'node', 'id' => 4, 'lat' => 10.3002, 'lon' => 123.87, 'tags' => []],
            ['type' => 'way', 'id' => 100, 'nodes' => [1, 2], 'tags' => ['highway' => 'footway']],
            ['type' => 'way', 'id' => 101, 'nodes' => [3, 4], 'tags' => ['highway' => 'footway']],
        ];
        file_put_contents($path, json_encode(['elements' => $elements], JSON_THROW_ON_ERROR));

        try {
            app(PedestrianNetworkImporter::class)->import($path, 'https://synthetic.invalid/geometric-crossing-test.json');
            $route = app(LocalPedestrianGraphRouter::class)->route($this->request(
                ['lat' => 10.3, 'lng' => 123.87],
                ['lat' => 10.3, 'lng' => 123.8702],
            ));
            $this->assertSame(PedestrianRouteStatus::NO_ROUTE, $route->status);
            $this->assertNull($route->geometry);
            $this->assertSame(0, DB::table('field_observations')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_private_service_access_is_excluded_unless_pedestrian_access_is_explicit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'synthetic-service-access-');
        if ($path === false) {
            $this->fail('Could not create temporary synthetic service-road fixture.');
        }
        $elements = [
            ['type' => 'node', 'id' => 1, 'lat' => 10.3, 'lon' => 123.87, 'tags' => []],
            ['type' => 'node', 'id' => 2, 'lat' => 10.3001, 'lon' => 123.87, 'tags' => []],
            ['type' => 'node', 'id' => 3, 'lat' => 10.3, 'lon' => 123.8702, 'tags' => []],
            ['type' => 'node', 'id' => 4, 'lat' => 10.3001, 'lon' => 123.8702, 'tags' => []],
            ['type' => 'way', 'id' => 100, 'nodes' => [1, 2], 'tags' => ['highway' => 'service', 'service' => 'driveway']],
            ['type' => 'way', 'id' => 101, 'nodes' => [3, 4], 'tags' => ['highway' => 'service', 'service' => 'driveway', 'foot' => 'yes']],
        ];
        file_put_contents($path, json_encode(['elements' => $elements], JSON_THROW_ON_ERROR));

        try {
            $import = app(PedestrianNetworkImporter::class)->import($path, 'https://synthetic.invalid/service-access-test.json');
            $this->assertSame(2, $import->nodes);
            $this->assertSame(1, $import->edges);
            $this->assertSame(1, $import->restrictedElements);
            $this->assertSame(0, DB::table('field_observations')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_import_is_idempotent_bounded_and_rejects_broken_references(): void
    {
        $path = $this->writeSyntheticExtract();
        try {
            $importer = app(PedestrianNetworkImporter::class);
            $first = $importer->import($path, 'https://synthetic.invalid/cebu-test-extract.json');
            $second = $importer->import($path, 'https://synthetic.invalid/cebu-test-extract.json');
            $this->assertTrue($second->alreadyImported);
            $this->assertSame($first->stableId, $second->stableId);
            $this->assertSame(1, DB::table('pedestrian_network_datasets')->where('is_active', true)->count());

            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $document['elements'] = array_values(array_filter($document['elements'], static fn (array $element): bool => ! (($element['type'] ?? null) === 'node' && ($element['id'] ?? null) === 2)));
            file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
            try {
                $importer->import($path, 'https://synthetic.invalid/broken-cebu-test-extract.json');
                $this->fail('Expected import validation to reject a missing way reference.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('references a node missing', $exception->getMessage());
            }

            $this->assertSame(1, DB::table('pedestrian_network_datasets')->count());
            $this->assertSame(0, DB::table('field_observations')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_graph_validation_failure_keeps_the_previous_dataset_active(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This rollback fixture uses a SQLite trigger to corrupt a staged edge.');
        }

        $currentPath = $this->writeSyntheticExtract();
        $updatePath = $this->writeExtractWithAdditionalWay($currentPath);
        $trigger = 'pedestrian_test_corrupt_staged_edge';

        try {
            $current = app(PedestrianNetworkImporter::class)->import($currentPath, 'https://synthetic.invalid/current-extract.json');
            DB::unprepared("CREATE TRIGGER {$trigger} AFTER INSERT ON pedestrian_network_edges WHEN NEW.osm_way_id = 200 BEGIN UPDATE pedestrian_network_edges SET length_meters = 0 WHERE id = NEW.id; END");

            try {
                app(PedestrianNetworkImporter::class)->import($updatePath, 'https://synthetic.invalid/updated-extract.json');
                $this->fail('Expected graph validation to reject a staged zero-length edge.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('validation failed before activation', $exception->getMessage());
            }

            $this->assertSame($current->stableId, DB::table('pedestrian_network_datasets')->where('is_active', true)->value('stable_id'));
            $this->assertSame(1, DB::table('pedestrian_network_datasets')->count());
            $this->assertSame($current->nodes, DB::table('pedestrian_network_nodes')->count());
            $this->assertSame($current->edges, DB::table('pedestrian_network_edges')->count());
        } finally {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            @unlink($currentPath);
            @unlink($updatePath);
        }
    }

    public function test_incomplete_inactive_dataset_is_not_reactivated_by_an_idempotent_import(): void
    {
        $currentPath = $this->writeSyntheticExtract();
        $updatePath = $this->writeExtractWithAdditionalWay($currentPath);

        try {
            $importer = app(PedestrianNetworkImporter::class);
            $oldDataset = $importer->import($currentPath, 'https://synthetic.invalid/current-extract.json');
            $currentDataset = $importer->import($updatePath, 'https://synthetic.invalid/updated-extract.json');
            $oldDatasetId = DB::table('pedestrian_network_datasets')->where('stable_id', $oldDataset->stableId)->value('id');

            $removedEdgeId = DB::table('pedestrian_network_edges')
                ->where('dataset_id', $oldDatasetId)
                ->value('id');
            DB::table('pedestrian_network_edges')->where('id', $removedEdgeId)->delete();

            try {
                $importer->import($currentPath, 'https://synthetic.invalid/current-extract.json');
                $this->fail('Expected idempotent import to reject the incomplete stored dataset.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('validation failed before activation', $exception->getMessage());
            }

            $this->assertSame($currentDataset->stableId, DB::table('pedestrian_network_datasets')->where('is_active', true)->value('stable_id'));
            $this->assertSame(1, DB::table('pedestrian_network_datasets')->where('is_active', true)->count());
        } finally {
            @unlink($currentPath);
            @unlink($updatePath);
        }
    }

    public function test_osmium_xml_preparation_preserves_source_provenance_and_rejects_malformed_input_atomically(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'synthetic-osm-xml-');
        $output = tempnam(sys_get_temp_dir(), 'synthetic-osm-json-');
        if ($input === false || $output === false) {
            $this->fail('Could not create temporary synthetic OSM fixture files.');
        }
        $sourceUrl = 'https://synthetic.invalid/test-cebu.osm.pbf';
        $sourceMd5 = '0123456789abcdef0123456789abcdef';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><osm version="0.6" timestamp="2026-10-05T14:00:02Z">'
            .'<node id="1" lat="10.3" lon="123.87"><tag k="highway" v="crossing"/><tag k="crossing" v="marked"/></node>'
            .'<node id="2" lat="10.3001" lon="123.87"/>'
            .'<way id="100"><nd ref="1"/><nd ref="2"/><tag k="highway" v="footway"/></way></osm>';
        file_put_contents($input, $xml);

        try {
            $exitCode = Artisan::call('biyahe:pedestrian:prepare-osm-xml', [
                'file' => $input,
                '--output' => $output,
                '--source-url' => $sourceUrl,
                '--source-file' => 'test-cebu.osm.pbf',
                '--source-md5' => $sourceMd5,
                '--source-elements' => '3',
                '--osmium-version' => '1.19.1',
            ]);
            $this->assertSame(0, $exitCode, Artisan::output());
            $prepared = json_decode((string) file_get_contents($output), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame('osmium-osm-xml-v1', $prepared['biyahe_source']['format']);
            $this->assertSame($sourceMd5, $prepared['biyahe_source']['source_checksum_md5']);
            $this->assertSame('2026-10-05T14:00:02+00:00', $prepared['biyahe_source']['source_timestamp']);

            $import = app(PedestrianNetworkImporter::class)->import($output, $sourceUrl);
            $this->assertSame(2, $import->nodes);
            $this->assertSame(1, $import->edges);
            $this->assertSame('marked', DB::table('pedestrian_network_nodes')->where('osm_node_id', 1)->value('crossing'));
            $storedMetadata = json_decode((string) DB::table('pedestrian_network_datasets')->value('metadata'), true);
            $this->assertSame($sourceMd5, $storedMetadata['source_details']['source_checksum_md5']);
            $this->assertSame('ODbL-1.0', $storedMetadata['source_details']['license']);
            $this->assertSame(0, DB::table('field_observations')->count());
            $this->assertSame(0, Artisan::call('biyahe:pedestrian:status', ['--json' => true]));
            $status = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('PASS', $status['validation']);
            $this->assertSame(['nodes' => 2, 'edges' => 1], $status['active_dataset']['actual_counts']);
            $this->assertSame(2, $status['active_dataset']['walkable_nodes']);
            $this->assertSame('ACTIVE_AND_VALID', $status['last_import_result']['status']);

            file_put_contents($output, 'preserve-existing-output');
            file_put_contents($input, substr($xml, 0, -6));
            $exitCode = Artisan::call('biyahe:pedestrian:prepare-osm-xml', [
                'file' => $input,
                '--output' => $output,
                '--source-url' => $sourceUrl,
                '--source-file' => 'test-cebu.osm.pbf',
            ]);
            $this->assertSame(1, $exitCode);
            $this->assertSame('preserve-existing-output', file_get_contents($output));
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }

    public function test_missing_graph_coverage_is_not_reported_as_a_computed_walk_route(): void
    {
        $router = app(LocalPedestrianGraphRouter::class);
        $result = $router->route($this->request(
            ['lat' => 10.3, 'lng' => 123.87],
            ['lat' => 10.3002, 'lng' => 123.87],
        ));
        $this->assertSame(PedestrianRouteStatus::UNAVAILABLE, $result->status);
        $this->assertNull($result->geometry);
        $this->assertNull($result->distanceMeters);
    }

    public function test_postgis_snap_refuses_a_connector_crossing_a_mapped_barrier(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Mapped-barrier connector check requires PostGIS.');
        }

        $path = tempnam(sys_get_temp_dir(), 'synthetic-barrier-network-');
        if ($path === false) {
            $this->fail('Could not create temporary synthetic barrier fixture.');
        }
        $elements = [
            ['type' => 'node', 'id' => 1, 'lat' => 10.3, 'lon' => 123.8701, 'tags' => []],
            ['type' => 'node', 'id' => 2, 'lat' => 10.3, 'lon' => 123.8702, 'tags' => []],
            ['type' => 'node', 'id' => 3, 'lat' => 10.3, 'lon' => 123.87, 'tags' => ['barrier' => 'fence']],
            ['type' => 'way', 'id' => 100, 'nodes' => [1, 2], 'tags' => ['highway' => 'footway']],
        ];
        file_put_contents($path, json_encode(['elements' => $elements], JSON_THROW_ON_ERROR));

        try {
            app(PedestrianNetworkImporter::class)->import($path, 'https://synthetic.invalid/barrier-test.json');
            $route = app(LocalPedestrianGraphRouter::class)->route($this->request(
                ['lat' => 10.3, 'lng' => 123.87],
                ['lat' => 10.3, 'lng' => 123.8702],
            ));
            $this->assertSame(PedestrianRouteStatus::UNAVAILABLE, $route->status);
            $this->assertStringContainsString('mapped barrier', $route->warnings[0]);
            $this->assertNull($route->geometry);
        } finally {
            @unlink($path);
        }
    }

    public function test_journey_api_returns_separate_network_walk_geometry_without_promoting_transit_trust(): void
    {
        $this->seed();
        $origin = Place::query()->where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::query()->where('slug', 'carbon-market-unit-3')->firstOrFail();
        $boardingStop = Stop::query()->where('name', 'JY Square Mall')->firstOrFail();
        $alightingStop = Stop::query()->where('name', 'Carbon Market Unit 3')->firstOrFail();
        $origin->location = ['lat' => 10.3, 'lng' => 123.87];
        $origin->save();
        $boardingStop->location = ['lat' => 10.3, 'lng' => 123.8702];
        $boardingStop->save();
        $alightingStop->location = ['lat' => 10.3002, 'lng' => 123.8702];
        $alightingStop->save();
        $destination->location = ['lat' => 10.3002, 'lng' => 123.87];
        $destination->save();

        $path = $this->writeSyntheticExtract();
        try {
            app(PedestrianNetworkImporter::class)->import($path, 'https://synthetic.invalid/cebu-test-extract.json');
            $response = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);
            $response->assertOk();

            $journey = collect($response->json('data'))->first(function (array $candidate): bool {
                return $candidate['transfer_count'] === 0
                    && collect($candidate['legs'])->contains(fn (array $leg): bool => $leg['type'] === 'RIDE' && $leg['route']['code'] === '17B');
            });
            $this->assertNotNull($journey);
            $this->assertSame('MIXED', $journey['trust']['state']);
            $this->assertSame('per_leg', $response->json('meta.walking_distance_type'));
            $this->assertGreaterThan(0, $response->json('meta.pedestrian_routing.routes_computed'));
            $this->assertGreaterThan(0, $response->json('meta.pedestrian_routing.routes_available'));

            $walkingLegs = collect($journey['legs'])->whereIn('type', ['WALK_ACCESS', 'WALK_EGRESS']);
            $this->assertCount(2, $walkingLegs);
            foreach ($walkingLegs as $leg) {
                $this->assertSame('PEDESTRIAN_NETWORK', $leg['distance_type']);
                $this->assertSame('AVAILABLE', $leg['routing_status']);
                $this->assertSame('LineString', $leg['geometry']['type']);
                $this->assertNotNull($leg['estimated_duration_seconds']);
            }
            $this->assertSame(0, DB::table('field_observations')->count());
        } finally {
            @unlink($path);
        }
    }

    private function request(array $origin, array $destination, float $maximumDistance = 2000): PedestrianRouteRequest
    {
        return new PedestrianRouteRequest($origin, $destination, PedestrianRoutePurpose::ACCESS_WALK, $maximumDistance);
    }

    private function writeSyntheticExtract(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'synthetic-pedestrian-network-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create temporary synthetic graph fixture.');
        }
        $elements = [];
        $coordinates = [
            1 => [10.3, 123.87],
            2 => [10.3, 123.8702],
            3 => [10.3002, 123.8702],
            4 => [10.3002, 123.87],
            5 => [10.3004, 123.87],
            6 => [10.3004, 123.8702],
            7 => [10.3006, 123.87],
            8 => [10.3006, 123.8702],
            10 => [10.3008, 123.8702],
            11 => [10.301, 123.87],
            12 => [10.301, 123.8702],
        ];
        foreach ($coordinates as $id => [$latitude, $longitude]) {
            $elements[] = ['type' => 'node', 'id' => $id, 'lat' => $latitude, 'lon' => $longitude, 'tags' => []];
        }
        $elements[] = ['type' => 'way', 'id' => 101, 'nodes' => [1, 2, 3, 4], 'tags' => ['highway' => 'residential']];
        $elements[] = ['type' => 'way', 'id' => 102, 'nodes' => [5, 6], 'tags' => ['highway' => 'footway']];
        $elements[] = ['type' => 'way', 'id' => 103, 'nodes' => [1, 5], 'tags' => ['highway' => 'service', 'foot' => 'no']];
        $elements[] = ['type' => 'way', 'id' => 104, 'nodes' => [7, 8], 'tags' => ['highway' => 'footway', 'oneway:foot' => 'yes']];
        $elements[] = ['type' => 'node', 'id' => 9, 'lat' => 10.3008, 'lon' => 123.87, 'tags' => ['barrier' => 'fence']];
        $elements[] = ['type' => 'way', 'id' => 105, 'nodes' => [9, 10], 'tags' => ['highway' => 'footway']];
        $elements[] = ['type' => 'way', 'id' => 106, 'nodes' => [11, 12], 'tags' => ['highway' => 'footway', 'access' => 'unknown']];
        file_put_contents($path, json_encode(['elements' => $elements], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function writeExtractWithAdditionalWay(string $path): string
    {
        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $document['elements'][] = ['type' => 'node', 'id' => 20, 'lat' => 10.302, 'lon' => 123.88, 'tags' => []];
        $document['elements'][] = ['type' => 'node', 'id' => 21, 'lat' => 10.3021, 'lon' => 123.88, 'tags' => []];
        $document['elements'][] = ['type' => 'way', 'id' => 200, 'nodes' => [20, 21], 'tags' => ['highway' => 'footway']];
        $variantPath = tempnam(sys_get_temp_dir(), 'synthetic-pedestrian-update-');
        if ($variantPath === false) {
            throw new \RuntimeException('Cannot create temporary pedestrian graph update fixture.');
        }
        file_put_contents($variantPath, json_encode($document, JSON_THROW_ON_ERROR));

        return $variantPath;
    }
}
