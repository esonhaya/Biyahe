<?php

namespace Tests\Feature;

use App\Enums\CommuterReportStatus;
use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use App\Models\CommuterReport;
use App\Models\Disruption;
use App\Models\DisruptionReview;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\User;
use App\Services\Disruptions\CommuterReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisruptionAwarenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_active_advisory_is_public_but_remains_a_temporary_overlay(): void
    {
        $route = Route::query()->where('code', '12L')->firstOrFail();
        $source = $this->source('OFFICIAL');
        $advisory = Disruption::query()->create([
            'stable_id' => 'test-12l-event',
            'city_id' => $route->city_id,
            'provenance_source_id' => $source->id,
            'category' => DisruptionCategory::EVENT,
            'headline' => 'Temporary event near the route',
            'description' => 'Synthetic test advisory only.',
            'severity' => DisruptionSeverity::MAJOR,
            'status' => DisruptionStatus::ACTIVE,
            'source_class' => DisruptionSourceClass::OFFICIAL,
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
            'reviewed_at' => now(),
            'is_synthetic' => true,
        ]);
        $advisory->routes()->attach($route);

        $response = $this->getJson('/api/v1/disruptions?route_id='.$route->id);
        $response->assertOk()->assertJsonPath('data.0.stable_id', 'test-12l-event');
        $response->assertJsonPath('data.0.status', 'ACTIVE');
        $origin = Place::query()->where('slug', 'ayala-center-cebu')->firstOrFail();
        $destination = Place::query()->where('slug', 'labangon')->firstOrFail();
        $journeyResponse = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);
        $journeyResponse->assertOk();
        $twelveL = collect($journeyResponse->json('data'))->first(function (array $journey): bool {
            return collect($journey['legs'])->contains(fn (array $leg): bool => ($leg['route']['code'] ?? null) === '12L');
        });
        $this->assertNotNull($twelveL);
        $this->assertSame('AFFECTED', $twelveL['operational_impact']);
        $twelveLLeg = collect($twelveL['legs'])->firstWhere('route.code', '12L');
        $this->assertSame('BOARDING_AREA', $twelveLLeg['boarding_stop']['boarding_precision']);
        $this->assertNull(Route::query()->findOrFail($route->id)->temporary_disruption ?? null);
    }

    public function test_reviewed_route_warning_is_attached_to_journey_without_changing_transport_trust(): void
    {
        $route = Route::query()->where('code', '17B')->firstOrFail();
        $source = $this->source('OPERATOR');
        $advisory = Disruption::query()->create([
            'stable_id' => 'test-17b-traffic',
            'city_id' => $route->city_id,
            'provenance_source_id' => $source->id,
            'category' => DisruptionCategory::TRAFFIC_DISRUPTION,
            'headline' => 'Traffic reported on the corridor',
            'description' => 'Synthetic warning-only advisory.',
            'severity' => DisruptionSeverity::MAJOR,
            'status' => DisruptionStatus::ACTIVE,
            'source_class' => DisruptionSourceClass::OPERATOR,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'reviewed_at' => now(),
            'is_synthetic' => true,
        ]);
        $advisory->routes()->attach($route);
        $origin = Place::query()->where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::query()->where('slug', 'carbon-market-unit-3')->firstOrFail();

        $response = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);
        $response->assertOk();
        $affected = collect($response->json('data'))->first(function (array $journey): bool {
            return collect($journey['legs'])->contains(fn (array $leg): bool => ($leg['route']['code'] ?? null) === '17B');
        });
        $this->assertNotNull($affected);
        $this->assertSame('MIXED', $affected['trust']['state']);
        $this->assertSame('AFFECTED', $affected['operational_impact']);
        $this->assertNotEmpty($affected['disruptions']);
    }

    public function test_public_report_requires_review_and_promotion_is_warning_only(): void
    {
        $route = Route::query()->where('code', '17B')->firstOrFail();
        $payload = [
            'category' => 'SERVICE_STOPPED',
            'description' => '<b>Service is not visible</b>',
            'observed_at' => now()->subMinutes(5)->toIso8601String(),
            'route_id' => $route->id,
            'location' => ['lat' => 10.3157, 'lng' => 123.8854],
        ];
        $submitted = $this->postJson('/api/v1/commuter-reports', $payload);
        $submitted->assertCreated()->assertJsonPath('data.status', CommuterReportStatus::SUBMITTED->value);
        $this->assertStringNotContainsString('<b>', (string) $submitted->json('data.description'));
        $duplicate = $this->postJson('/api/v1/commuter-reports', $payload);
        $duplicate->assertCreated()->assertJsonPath('data.status', CommuterReportStatus::DUPLICATE->value);

        $report = CommuterReport::query()->where('status', CommuterReportStatus::SUBMITTED->value)->firstOrFail();
        $actor = User::factory()->create();
        $service = app(CommuterReportService::class);
        $service->review($report, CommuterReportStatus::UNDER_REVIEW, $actor, 'Check signal.');
        $report = $service->review($report->fresh(), CommuterReportStatus::ACCEPTED_AS_SIGNAL, $actor, 'Accepted as an operational signal.');
        $advisory = $service->promoteToAdvisory($report, $actor, 'Temporary signal only.');

        $this->assertSame(DisruptionSourceClass::PUBLIC_REPORT, $advisory->source_class);
        $this->assertSame(DisruptionCategory::SERVICE_SUSPENDED, $advisory->category);
        $this->assertSame(DisruptionSeverity::MINOR, $advisory->severity);
        $this->assertTrue(DisruptionReview::query()->where('disruption_id', $advisory->id)->exists());
        $this->assertSame('17B', $route->fresh()->code);
        $this->assertNull($route->fresh()->temporary_disruption ?? null);
    }

    public function test_corroboration_keeps_conflicting_operational_reports_uncertain(): void
    {
        $route = Route::query()->where('code', '17B')->firstOrFail();
        $observedAt = now()->subMinutes(5);
        $service = app(CommuterReportService::class);
        $first = $service->submit([
            'category' => 'SERVICE_STOPPED',
            'description' => 'No 17B service observed.',
            'observed_at' => $observedAt->toIso8601String(),
            'route_id' => $route->id,
        ]);
        $second = $service->submit([
            'category' => 'ROUTE_NOT_SEEN',
            'description' => '17B was not seen at the corridor.',
            'observed_at' => $observedAt->toIso8601String(),
            'route_id' => $route->id,
        ]);
        $actor = User::factory()->create();
        $service->review($first, CommuterReportStatus::ACCEPTED_AS_SIGNAL, $actor, 'Accepted signal.');
        $service->review($second, CommuterReportStatus::ACCEPTED_AS_SIGNAL, $actor, 'Accepted signal.');

        $corroboration = $service->corroboration($first->fresh());

        $this->assertSame(0, $corroboration['accepted_matching_reports']);
        $this->assertSame(1, $corroboration['conflicting_accepted_reports']);
        $this->assertTrue($corroboration['is_conflicted']);
        $this->assertFalse($corroboration['is_canonical_evidence']);
    }

    public function test_reviewed_hard_service_closure_temporarily_removes_matching_candidates(): void
    {
        $route = Route::query()->where('code', '17B')->firstOrFail();
        $source = $this->source('OFFICIAL');
        $advisory = Disruption::query()->create([
            'stable_id' => 'test-17b-suspended',
            'city_id' => $route->city_id,
            'provenance_source_id' => $source->id,
            'category' => DisruptionCategory::SERVICE_SUSPENDED,
            'headline' => '17B temporarily suspended',
            'description' => 'Synthetic hard-closure test.',
            'severity' => DisruptionSeverity::CRITICAL,
            'status' => DisruptionStatus::ACTIVE,
            'source_class' => DisruptionSourceClass::OFFICIAL,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'reviewed_at' => now(),
            'is_synthetic' => true,
        ]);
        $advisory->routes()->attach($route);
        $origin = Place::query()->where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::query()->where('slug', 'carbon-market-unit-3')->firstOrFail();

        $response = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);
        $response->assertOk();
        foreach ($response->json('data') as $journey) {
            $this->assertFalse(collect($journey['legs'])->contains(fn (array $leg): bool => ($leg['route']['code'] ?? null) === '17B'));
        }
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_expired_advisory_is_not_publicly_active_or_planner_eligible(): void
    {
        $route = Route::query()->where('code', '12L')->firstOrFail();
        $source = $this->source('OFFICIAL');
        $advisory = Disruption::query()->create([
            'stable_id' => 'test-expired-advisory',
            'city_id' => $route->city_id,
            'provenance_source_id' => $source->id,
            'category' => DisruptionCategory::SERVICE_REDUCED,
            'headline' => 'Expired service note',
            'description' => 'Synthetic expired advisory.',
            'severity' => DisruptionSeverity::MAJOR,
            'status' => DisruptionStatus::ACTIVE,
            'source_class' => DisruptionSourceClass::OFFICIAL,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->subMinute(),
            'reviewed_at' => now()->subHour(),
            'is_synthetic' => true,
        ]);
        $advisory->routes()->attach($route);

        $this->getJson('/api/v1/disruptions')->assertOk()->assertJsonMissing(['stable_id' => 'test-expired-advisory']);
    }

    private function source(string $type): ProvenanceSource
    {
        return ProvenanceSource::factory()->create([
            'source_type' => $type,
            'title' => 'Synthetic disruption source',
            'checked_at' => now()->toDateString(),
            'observed_at' => now()->toDateString(),
            'verified_by' => 'Batch 014 test',
            'evidence_scope' => ['temporary_disruption_signal'],
        ]);
    }
}
