<?php

namespace Tests\Feature;

use App\Models\RoutePatternStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiyaheDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_refuses_to_claim_postgis_on_sqlite(): void
    {
        $this->seed();

        $artisan = $this->artisan('biyahe:doctor')
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

    public function test_doctor_fails_when_a_routing_pattern_loses_usable_permissions(): void
    {
        $this->seed();

        RoutePatternStop::query()->update(['boarding_allowed' => false]);

        $this->artisan('biyahe:doctor')
            ->expectsOutputToContain('boarding and alighting permissions')
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }
}
