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
            $this->assertSame(8, $import->nodes);
            $this->assertSame(5, $import->edges);
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
        ];
        foreach ($coordinates as $id => [$latitude, $longitude]) {
            $elements[] = ['type' => 'node', 'id' => $id, 'lat' => $latitude, 'lon' => $longitude, 'tags' => []];
        }
        $elements[] = ['type' => 'way', 'id' => 101, 'nodes' => [1, 2, 3, 4], 'tags' => ['highway' => 'residential']];
        $elements[] = ['type' => 'way', 'id' => 102, 'nodes' => [5, 6], 'tags' => ['highway' => 'footway']];
        $elements[] = ['type' => 'way', 'id' => 103, 'nodes' => [1, 5], 'tags' => ['highway' => 'service', 'foot' => 'no']];
        $elements[] = ['type' => 'way', 'id' => 104, 'nodes' => [7, 8], 'tags' => ['highway' => 'footway', 'oneway:foot' => 'yes']];
        file_put_contents($path, json_encode(['elements' => $elements], JSON_THROW_ON_ERROR));

        return $path;
    }
}
