<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Services\Evidence\EvidenceCoverageReport;
use App\Services\Evidence\EvidenceJourneyPlanCache;
use App\Services\Evidence\EvidencePriorityEngine;
use App\Services\Routing\Journey;
use App\Services\Routing\JourneyLeg;
use App\Services\Routing\JourneyPlanner;
use App\Services\Routing\JourneyPlanningContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgisTransportTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshPostgisTestDatabase;
    }

    protected function refreshDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (config('database.default') === 'pgsql' && preg_match('/(^|[_-])test([_-]|$)/i', $database) !== 1) {
            $this->markTestSkipped('PostGIS RefreshDatabase tests require a separately named database containing "test"; refusing to reset a production database.');

            return;
        }

        $this->refreshPostgisTestDatabase();
    }

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

    public function test_report_scoped_context_preserves_postgis_two_transfer_results(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostGIS integration requires DB_CONNECTION=pgsql.');
        }

        $this->seed();
        $loadPlace = fn (string $slug): Place => Place::query()
            ->with('aliases')
            ->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')])
            ->where('slug', $slug)
            ->firstOrFail();
        $origin = $loadPlace('cebu-it-park');
        $destination = $loadPlace('sm-city-cebu');
        $planner = app(JourneyPlanner::class);

        $uncached = $planner->plan($origin, $destination, maxTransfers: 2);
        $context = new JourneyPlanningContext(250);
        $cached = $planner->plan($origin, $destination, maxTransfers: 2, context: $context);
        $signature = static fn (Journey $journey): array => [
            $journey->id,
            $journey->trustState->value,
            array_map(static fn (JourneyLeg $leg): array => [
                $leg->type->value,
                $leg->route['code'] ?? null,
                $leg->pattern['direction'] ?? null,
                $leg->boardingStop['id'] ?? null,
                $leg->alightingStop['id'] ?? null,
                $leg->boardingSequence,
                $leg->alightingSequence,
                $leg->distanceMeters,
            ], $journey->legs),
        ];

        $this->assertNotEmpty($uncached->journeys);
        $this->assertSame(
            array_map($signature, $uncached->journeys),
            array_map($signature, $cached->journeys),
        );
        $this->assertGreaterThan(0, $context->metrics()['trust_cache_hits']);
    }

    public function test_12l_can_i_ride_uses_terminal_evidence_without_trust_escalation(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostGIS integration requires DB_CONNECTION=pgsql.');
        }

        $this->seed();
        $originId = DB::table('places')->where('slug', 'ayala-center-cebu')->value('id');
        $destinationId = DB::table('places')->where('slug', 'labangon')->value('id');
        $response = $this->getJson('/api/v1/can-i-ride?route_code=12L&from_place_id='.$originId.'&to_place_id='.$destinationId);

        $response->assertOk()
            ->assertJsonPath('data.status', 'YES')
            ->assertJsonPath('data.option.pattern.direction', 'OUTBOUND')
            ->assertJsonPath('data.option.boarding_stop.boarding_precision', 'BOARDING_AREA');

        $option = $response->json('data.option');
        $boardingStop = DB::table('stops')->where('id', $option['boarding_stop']['id'])->value('stable_id');
        $this->assertSame('stop-ayala-center-terminal', $boardingStop);
        $this->assertLessThan($option['alighting_sequence'], $option['boarding_sequence']);
        $this->assertNotSame('VERIFIED', $response->json('data.trust'));
        $this->assertStringContainsString('mapped boarding area', strtolower($option['boarding_instruction']));
    }

    public function test_postgis_evidence_reports_resolve_places_for_journey_audits(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('PostGIS integration requires DB_CONNECTION=pgsql.');
        }

        $this->seed();

        $planCache = app(EvidenceJourneyPlanCache::class);
        $coverage = app(EvidenceCoverageReport::class)->report(true, false, $planCache);
        $this->assertSame(8, $coverage['curated_journeys_total']);
        $this->assertGreaterThan(0, collect($coverage['curated_journeys'])->where('status', 'ROUTABLE')->count());

        $priority = app(EvidencePriorityEngine::class)->analyze(limit: 5, maxPairs: 1, maxTransfers: 0, planCache: $planCache);
        $this->assertSame(1, $priority['pairs_examined']);
        $this->assertGreaterThan(0, $priority['journeys_examined']);
        $this->assertNotEmpty($priority['items']);
    }
}
