<?php

namespace Tests\Feature;

use App\Enums\CommuterReportStatus;
use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use App\Models\Disruption;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\User;
use App\Services\Disruptions\CommuterReportService;
use App\Services\Disruptions\PulseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PulseAndReplanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_pulse_distinguishes_reviewed_advisories_from_non_authoritative_signals(): void
    {
        $route = Route::query()->where('code', '12L')->firstOrFail();
        $source = $this->source('OFFICIAL');
        $advisory = Disruption::query()->create([
            'stable_id' => 'pulse-test-12l',
            'city_id' => $route->city_id,
            'provenance_source_id' => $source->id,
            'category' => DisruptionCategory::SERVICE_REDUCED,
            'headline' => '12L temporary warning',
            'description' => 'Synthetic Pulse fixture.',
            'severity' => DisruptionSeverity::MAJOR,
            'status' => DisruptionStatus::ACTIVE,
            'source_class' => DisruptionSourceClass::OFFICIAL,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'reviewed_at' => now(),
            'is_synthetic' => true,
        ]);
        $advisory->routes()->attach($route);
        $report = app(CommuterReportService::class)->submit([
            'category' => 'FLOODING',
            'description' => 'A recent signal near 12L.',
            'observed_at' => now()->subMinutes(5)->toIso8601String(),
            'route_id' => $route->id,
        ]);
        app(CommuterReportService::class)->review($report, CommuterReportStatus::ACCEPTED_AS_SIGNAL, User::factory()->create());

        $pulse = app(PulseService::class)->summary();

        $this->assertSame(1, $pulse['summary']['active_advisories']);
        $this->assertSame(1, $pulse['summary']['major_or_critical']);
        $this->assertSame(1, $pulse['summary']['routes_affected']);
        $this->assertSame(1, $pulse['summary']['unresolved_public_signals']);
        $this->assertSame('DISRUPTED', $pulse['network_status']['code']);
        $this->assertFalse($pulse['community_signals'][0]['authoritative']);
        $this->assertSame('pulse-test-12l', $pulse['items'][0]['stable_id']);
    }

    public function test_expired_advisory_is_excluded_from_pulse(): void
    {
        $route = Route::query()->where('code', '12L')->firstOrFail();
        $source = $this->source('OFFICIAL');
        $advisory = Disruption::query()->create([
            'stable_id' => 'pulse-test-expired',
            'city_id' => $route->city_id,
            'provenance_source_id' => $source->id,
            'category' => DisruptionCategory::EVENT,
            'headline' => 'Expired Pulse fixture',
            'description' => 'Synthetic expired fixture.',
            'severity' => DisruptionSeverity::CRITICAL,
            'status' => DisruptionStatus::ACTIVE,
            'source_class' => DisruptionSourceClass::OFFICIAL,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinute(),
            'reviewed_at' => now()->subHour(),
            'is_synthetic' => true,
        ]);
        $advisory->routes()->attach($route);

        $pulse = app(PulseService::class)->summary();

        $this->assertSame(0, $pulse['summary']['active_advisories']);
        $this->assertSame([], $pulse['items']);
    }

    public function test_replan_endpoint_reuses_current_planner_and_requires_explicit_client_switch(): void
    {
        $origin = Place::query()->where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::query()->where('slug', 'carbon-market-unit-3')->firstOrFail();
        $route = Route::query()->where('code', '17B')->firstOrFail();
        $source = $this->source('OPERATOR');
        $advisory = Disruption::query()->create([
            'stable_id' => 'replan-test-warning',
            'city_id' => $route->city_id,
            'provenance_source_id' => $source->id,
            'category' => DisruptionCategory::TRAFFIC_DISRUPTION,
            'headline' => '17B may be affected',
            'description' => 'Synthetic replan fixture.',
            'severity' => DisruptionSeverity::MAJOR,
            'status' => DisruptionStatus::ACTIVE,
            'source_class' => DisruptionSourceClass::OPERATOR,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'reviewed_at' => now(),
            'is_synthetic' => true,
        ]);
        $advisory->routes()->attach($route);

        $response = $this->postJson('/api/v1/journeys/replan', [
            'from_place_id' => $origin->id,
            'to_place_id' => $destination->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('meta.replan', true)
            ->assertJsonPath('meta.replan_status', 'ALTERNATIVES_FOUND');
        $this->assertNotEmpty($response->json('data'));
        $affected = collect($response->json('data'))->first(fn (array $journey): bool => collect($journey['legs'])->contains(fn (array $leg): bool => ($leg['route']['code'] ?? null) === '17B'));
        $this->assertNotNull($affected);
        $this->assertSame('MIXED', $affected['trust']['state']);
    }

    private function source(string $type): ProvenanceSource
    {
        return ProvenanceSource::factory()->create([
            'source_type' => $type,
            'title' => 'Synthetic Pulse source',
            'checked_at' => now()->toDateString(),
            'observed_at' => now()->toDateString(),
            'verified_by' => 'Batch 015 test',
            'evidence_scope' => ['temporary_disruption_signal'],
        ]);
    }
}
