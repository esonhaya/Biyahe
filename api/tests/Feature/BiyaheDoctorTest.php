<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiyaheDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_refuses_to_claim_postgis_on_sqlite(): void
    {
        $this->seed();

        $this->artisan('biyahe:doctor')
            ->expectsOutputToContain('postgis')
            ->expectsOutputToContain('BLOCKED_BY_ENVIRONMENT')
            ->assertExitCode(2);
    }
}
