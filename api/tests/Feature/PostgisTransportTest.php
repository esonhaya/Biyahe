<?php

namespace Tests\Feature;

use App\Services\Evidence\EvidenceCoverageReport;
use App\Services\Evidence\EvidencePriorityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgisTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_postgis_schema_and_spatial_api_behavior(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostGIS integration requires DB_CONNECTION=pgsql.');
        }

        $this->seed();

        $version = DB::selectOne('SELECT postgis_version() AS version');
        $this->assertNotEmpty($version->version);

        $placeColumn = DB::selectOne(
            "SELECT type, srid FROM geography_columns WHERE f_table_schema = current_schema() AND f_table_name = 'places' AND f_geography_column = 'location'",
        );
        $stopColumn = DB::selectOne(
            "SELECT type, srid FROM geography_columns WHERE f_table_schema = current_schema() AND f_table_name = 'stops' AND f_geography_column = 'location'",
        );
        $patternColumn = DB::selectOne(
            "SELECT type, srid FROM geometry_columns WHERE f_table_schema = current_schema() AND f_table_name = 'route_patterns' AND f_geometry_column = 'geometry'",
        );

        $this->assertSame('Point', $placeColumn->type);
        $this->assertSame(4326, (int) $placeColumn->srid);
        $this->assertSame('Point', $stopColumn->type);
        $this->assertSame(4326, (int) $stopColumn->srid);
        $this->assertSame('MULTILINESTRING', $patternColumn->type);
        $this->assertSame(4326, (int) $patternColumn->srid);

        $place = DB::selectOne(
            "SELECT ST_X(location::geometry) AS longitude, ST_Y(location::geometry) AS latitude FROM places WHERE stable_id = 'place-fuente-osmena-circle'",
        );
        $this->assertEqualsWithDelta(123.8926115, (float) $place->longitude, 0.0000001);
        $this->assertEqualsWithDelta(10.3099383, (float) $place->latitude, 0.0000001);

        $geometry = DB::selectOne(
            "SELECT ST_IsValid(geometry) AS valid, ST_SRID(geometry) AS srid, GeometryType(geometry) AS type FROM route_patterns WHERE stable_id = 'pattern-17b-forward'",
        );
        $this->assertTrue((bool) $geometry->valid);
        $this->assertSame(4326, (int) $geometry->srid);
        $this->assertSame('MULTILINESTRING', $geometry->type);

        $indexes = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->whereIn('indexname', ['places_location_gix', 'stops_location_gix', 'route_patterns_geometry_gix'])
            ->pluck('indexname')
            ->all();
        $this->assertEqualsCanonicalizing(
            ['places_location_gix', 'stops_location_gix', 'route_patterns_geometry_gix'],
            $indexes,
        );

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('postgis.status', 'PASS');

        $this->getJson('/api/v1/stops/nearby?lat=10.3096109&lng=123.8923968&radius=100')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'OSM-6292686585')
            ->assertJsonPath('data.0.distance_meters', 0)
            ->assertJsonPath('meta.distance_unit', 'meters');

        $offsetResponse = $this->getJson('/api/v1/stops/nearby?lat=10.3106109&lng=123.8923968&radius=500');
        $offsetResponse->assertOk()->assertJsonPath('meta.distance_unit', 'meters');
        $offsetDistance = (float) $offsetResponse->json('data.0.distance_meters');
        $this->assertGreaterThan(100, $offsetDistance);
        $this->assertLessThan(130, $offsetDistance);
    }

    public function test_real_postgis_journey_search_uses_bounded_meter_candidates(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostGIS integration requires DB_CONNECTION=pgsql.');
        }

        $this->seed();
        $origin = DB::table('places')->where('slug', 'ayala-center-cebu')->value('id');
        $destination = DB::table('places')->where('slug', 'carbon-market-unit-3')->value('id');

        $response = $this->getJson('/api/v1/journeys?from_place_id='.$origin.'&to_place_id='.$destination);
        $response
            ->assertOk()
            ->assertJsonPath('meta.distance_unit', 'meters')
            ->assertJsonPath('meta.walking_distance_type', 'straight_line_estimate');

        $transfer = collect($response->json('data'))->firstWhere('transfer_count', 1);
        $this->assertNotNull($transfer);
        $this->assertSame('TRANSFER_WALK', $transfer['legs'][2]['type']);
        $this->assertGreaterThan(0, $transfer['legs'][0]['distance_meters']);
        $this->assertLessThanOrEqual(600, $transfer['legs'][2]['distance_meters']);
    }

    public function test_postgis_evidence_reports_resolve_places_for_journey_audits(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostGIS integration requires DB_CONNECTION=pgsql.');
        }

        $this->seed();

        $coverage = app(EvidenceCoverageReport::class)->report(true, false);
        $this->assertSame(8, $coverage['curated_journeys_total']);
        $this->assertGreaterThan(0, collect($coverage['curated_journeys'])->where('status', 'ROUTABLE')->count());

        $priority = app(EvidencePriorityEngine::class)->analyze(limit: 5, maxPairs: 1, maxTransfers: 0);
        $this->assertSame(1, $priority['pairs_examined']);
        $this->assertGreaterThan(0, $priority['journeys_examined']);
        $this->assertNotEmpty($priority['items']);
    }
}
