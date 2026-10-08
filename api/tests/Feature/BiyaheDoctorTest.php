<?php

namespace Tests\Feature;

use App\Enums\SurveySessionStatus;
use App\Models\City;
use App\Models\FieldObservation;
use App\Models\RoutePatternStop;
use App\Models\TransportSurveySession;
use App\Models\User;
use App\Services\FieldSurveys\FieldSurveyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BiyaheDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_refuses_to_claim_postgis_on_sqlite(): void
    {
        $this->seed();

        $artisan = $this->artisan('biyahe:doctor')
            ->expectsOutputToContain('BIYAHE DOCTOR V27')
            ->expectsOutputToContain('Batch 022 importer database boundary')
            ->expectsOutputToContain('postgis');

        if (config('database.default') === 'pgsql') {
            $artisan
                ->expectsOutputToContain('PASS')
                ->assertExitCode(0);

            return;
        }

        $artisan
            ->expectsOutputToContain('BLOCKED')
            ->assertExitCode(2);
    }

    public function test_static_doctor_runs_source_checks_and_marks_live_checks_blocked(): void
    {
        $exitCode = Artisan::call('biyahe:doctor', ['--static' => true]);
        $output = Artisan::output();

        $this->assertSame(2, $exitCode, $output);
        $this->assertStringContainsString('BIYAHE DOCTOR V27 STATIC', $output);
        $this->assertMatchesRegularExpression('/database\s+BLOCKED/', $output);
        $this->assertMatchesRegularExpression('/postgis\s+BLOCKED/', $output);
        $this->assertStringContainsString('frontend contract health', $output);
        $this->assertStringContainsString('OSM stream source and completeness', $output);
        $this->assertStringContainsString('database test and import boundary', $output);
        $this->assertStringContainsString('backup provenance', $output);
        $this->assertStringContainsString('restore test is recorded', $output);
        $this->assertStringContainsString('active graph integrity', $output);
        $this->assertStringContainsString('active graph readiness', $output);
        $this->assertStringContainsString('resource-aware importer guard', $output);
        $this->assertStringContainsString('frontend integration behavior', $output);
        $this->assertStringContainsString('field planning integrity', $output);
        $this->assertStringContainsString('summary: PASS=', $output);
        $this->assertStringContainsString('BLOCKED=', $output);
    }

    public function test_live_doctor_distinguishes_missing_and_invalid_graphs(): void
    {
        $this->seed();

        Artisan::call('biyahe:doctor');
        $missingGraphOutput = Artisan::output();
        $this->assertMatchesRegularExpression('/active graph readiness\s+WARN/', $missingGraphOutput);

        DB::table('pedestrian_network_datasets')->insert([
            'stable_id' => 'test-b027-invalid-active-graph',
            'source' => 'OPENSTREETMAP',
            'source_url' => 'https://example.invalid/test.osm.pbf',
            'license' => 'ODbL-1.0',
            'attribution' => '© OpenStreetMap contributors',
            'source_timestamp' => now(),
            'imported_at' => now(),
            'min_latitude' => 10.0,
            'min_longitude' => 123.8,
            'max_latitude' => 10.1,
            'max_longitude' => 123.9,
            'node_count' => 1,
            'edge_count' => 1,
            'content_hash' => str_repeat('b', 64),
            'metadata' => json_encode(['format' => 'test-only'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'graph_retained' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('biyahe:doctor');
        $invalidGraphOutput = Artisan::output();
        $this->assertMatchesRegularExpression('/active graph readiness\s+FAIL/', $invalidGraphOutput);
    }

    public function test_doctor_fails_for_invalid_pedestrian_routing_bounds(): void
    {
        $this->seed();
        config(['biyahe.pedestrian.maximum_transfer_walk_meters' => 0]);

        $this->artisan('biyahe:doctor')
            ->expectsOutputToContain('pedestrian routing config')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    public function test_doctor_fails_when_a_routing_pattern_loses_usable_permissions(): void
    {
        $this->seed();

        RoutePatternStop::query()->update(['boarding_allowed' => false]);

        $this->artisan('biyahe:doctor')
            ->expectsOutputToContain('boarding and alighting permissions')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    public function test_doctor_fails_for_unknown_field_location_method(): void
    {
        $this->seed();
        $session = TransportSurveySession::factory()->create();
        $observation = FieldObservation::factory()->create(['survey_session_id' => $session->id]);
        DB::table('field_observations')->where('id', $observation->id)->update(['location_method' => 'UNSUPPORTED_METHOD']);

        $this->artisan('biyahe:doctor')
            ->expectsOutputToContain('field review lifecycle')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    public function test_doctor_accepts_a_planned_survey_aborted_before_collection(): void
    {
        $this->seed();
        $session = TransportSurveySession::factory()->create([
            'stable_id' => 'test-only-aborted-planned-survey',
            'city_id' => City::query()->where('stable_id', 'city-cebu-city')->value('id'),
            'is_synthetic' => false,
        ]);
        app(FieldSurveyReviewService::class)->updateSessionStatus(
            $session,
            SurveySessionStatus::ABORTED,
            User::factory()->create(),
        );

        $exitCode = Artisan::call('biyahe:doctor');
        $output = Artisan::output();
        $this->assertStringContainsString('field survey lifecycle and references', $output);
        $this->assertSame(config('database.default') === 'pgsql' ? 0 : 2, $exitCode, $output);
    }
}
