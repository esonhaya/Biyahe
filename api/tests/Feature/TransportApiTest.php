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
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/places/search?q=DEMO%20Fuente')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'DEMO: Fuente Civic Landmark')
            ->assertJsonFragment(['alias' => 'DEMO Fuente']);
    }

    public function test_routes_patterns_stops_and_geometry_are_readable(): void
    {
        $routeResponse = $this->getJson('/api/v1/routes?per_page=1');
        $routeResponse->assertOk()->assertJsonPath('data.0.code', 'DEMO-04L');

        $routeId = $routeResponse->json('data.0.id');
        $this->getJson('/api/v1/routes/'.$routeId)
            ->assertOk()
            ->assertJsonCount(2, 'data.patterns')
            ->assertJsonPath('data.patterns.0.stops.0.sequence', 1);

        $patternId = $routeResponse->json('data.0.patterns.0.id');
        $this->getJson('/api/v1/route-patterns/'.$patternId)
            ->assertOk()
            ->assertJsonPath('data.geometry.type', 'MultiLineString')
            ->assertJsonPath('data.stops.1.stop.code', 'DEMO-D');

        $this->getJson('/api/v1/stops')
            ->assertOk()
            ->assertJsonPath('data.0.location.lat', 10.3159);
    }

    public function test_nearby_stops_use_bounded_parameters_and_return_distance(): void
    {
        $this->getJson('/api/v1/stops/nearby?lat=10.3159&lng=123.8857&radius=500')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'DEMO-A')
            ->assertJsonPath('data.0.distance_meters', 0);

        $this->getJson('/api/v1/stops/nearby?lat=999&lng=123.8857')
            ->assertStatus(422);

        $this->getJson('/api/v1/stops/nearby?lat=10.3159&lng=123.8857&radius=20001')
            ->assertStatus(422);
    }
}
