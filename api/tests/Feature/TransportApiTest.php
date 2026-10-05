<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_health_reports_postgis_blocked_on_sqlite_test_runtime(): void
    {
        $response = $this->getJson('/api/v1/health');

        if (config('database.default') === 'pgsql') {
            $response->assertOk()
                ->assertJsonPath('application.status', 'PASS')
                ->assertJsonPath('database.status', 'PASS')
                ->assertJsonPath('postgis.status', 'PASS');

            return;
        }

        $response->assertStatus(503)
            ->assertJsonPath('application.status', 'PASS')
            ->assertJsonPath('database.status', 'PASS')
            ->assertJsonPath('postgis.status', 'BLOCKED_BY_ENVIRONMENT');
    }

    public function test_cities_places_and_alias_search_return_consistent_data(): void
    {
        $this->getJson('/api/v1/cities')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'cebu-city');

        $this->getJson('/api/v1/places?city=cebu-city')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.verification.trusted', true);

        $this->getJson('/api/v1/places/search?q=IT%20Park')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cebu IT Park');

        $this->getJson('/api/v1/places/search?q=DEMO%20Fuente&include_demo=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'DEMO: Fuente Civic Landmark')
            ->assertJsonFragment(['alias' => 'DEMO Fuente']);
    }

    public function test_routes_patterns_stops_and_geometry_are_readable(): void
    {
        $routeResponse = $this->getJson('/api/v1/routes?q=17B&per_page=1');
        $routeResponse
            ->assertOk()
            ->assertJsonPath('data.0.code', '17B')
            ->assertJsonPath('data.0.verification.state', 'VERIFIED')
            ->assertJsonPath('data.0.verification.trusted', true);

        $routeId = $routeResponse->json('data.0.id');
        $this->getJson('/api/v1/routes/'.$routeId)
            ->assertOk()
            ->assertJsonCount(2, 'data.patterns')
            ->assertJsonPath('data.patterns.0.stops.0.sequence', 1);

        $patternId = $routeResponse->json('data.0.patterns.0.id');
        $this->getJson('/api/v1/route-patterns/'.$patternId)
            ->assertOk()
            ->assertJsonPath('data.geometry.type', 'MultiLineString')
            ->assertJsonPath('data.stops.3.sequence', 4)
            ->assertJsonFragment(['code' => 'OSM-6292686585']);

        $this->getJson('/api/v1/stops')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 48)
            ->assertJsonFragment(['code' => 'OSM-10251749651']);
    }

    public function test_nearby_stops_use_bounded_parameters_and_return_distance(): void
    {
        $this->getJson('/api/v1/stops/nearby?lat=10.3096109&lng=123.8923968&radius=500')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'OSM-6292686585')
            ->assertJsonPath('data.0.distance_meters', 0);

        $this->getJson('/api/v1/stops/nearby?lat=999&lng=123.8923968')
            ->assertStatus(422);

        $this->getJson('/api/v1/stops/nearby?lat=10.3096109&lng=123.8923968&radius=20001')
            ->assertStatus(422);
    }

    public function test_trusted_queries_and_detail_endpoints_preserve_data_boundaries(): void
    {
        $this->getJson('/api/v1/routes?trusted_only=1')
            ->assertOk()
            ->assertJsonCount(8, 'data');

        $this->getJson('/api/v1/routes?include_demo=1&q=DEMO-04L&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.verification.is_demo', true)
            ->assertJsonPath('data.0.verification.trusted', false);

        $this->getJson('/api/v1/cities/cebu-city')
            ->assertOk()
            ->assertJsonPath('data.verification.trusted', true);
    }
}
