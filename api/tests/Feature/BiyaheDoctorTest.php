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
            ->expectsOutputToContain('BIYAHE DOCTOR V23')
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
