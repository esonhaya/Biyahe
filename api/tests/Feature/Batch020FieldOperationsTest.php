<?php

namespace Tests\Feature;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimTargetType;
use App\Enums\FieldObservationStatus;
use App\Enums\VehicleTypeCode;
use App\Models\City;
use App\Models\FieldEvidenceClaim;
use App\Models\FieldMission;
use App\Models\FieldMissionClaim;
use App\Models\FieldObservation;
use App\Models\FieldReviewAction;
use App\Models\RoutePatternStop;
use App\Models\TransportSurveySession;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Data\DatasetImporter;
use App\Services\Data\DatasetValidator;
use App\Services\FieldSurveys\FieldMissionService;
use App\Services\FieldSurveys\FieldPromotionService;
use App\Services\FieldSurveys\FieldSurveyReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Batch020FieldOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_session_is_bounded_idempotent_and_never_auto_promotes(): void
    {
        $this->seedTransportNetwork();
        [$operator, $mission, $missionClaim, $patternStop] = $this->missionFixture();
        $service = app(FieldMissionService::class);
        $session = $service->startSession($mission, $operator);

        $payload = [
            'schema_version' => 1,
            'stable_id' => 'field-client-observation-001',
            'claim_id' => $missionClaim->stable_id,
            'type' => 'BOARDING_LOCATION',
            'observed_at' => now()->subMinutes(2)->toIso8601String(),
            'fact' => [
                'observed_precision' => 'EXACT_POINT_OBSERVED',
                'description' => '<b>Carbon area</b>',
            ],
            'notes' => '<script>ignored()</script> observed from the public side',
            'location' => ['lat' => 10.3157, 'lng' => 123.8854],
            'accuracy_m' => 120,
        ];

        $first = $service->submitObservation($session, $payload, $operator);
        $second = $service->submitObservation($session, $payload, $operator);

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame(1, FieldObservation::query()->count());
        $observation = $first['observation']->fresh();
        $this->assertSame('GENERAL_AREA', $observation->location_precision->value);
        $this->assertSame('Carbon area', $observation->observed_fact['description']);
        $this->assertStringNotContainsString('<script>', (string) $observation->notes);
        $this->assertFalse($patternStop->fresh()->boarding_verification_state?->value === 'VERIFIED');
        $this->assertDatabaseHas('field_review_actions', ['action' => 'OBSERVATION_SUBMITTED']);
    }

    public function test_review_and_promotion_are_separate_scoped_and_audited(): void
    {
        $this->seedTransportNetwork();
        [$operator, $mission, $missionClaim] = $this->missionFixture();
        $session = app(FieldMissionService::class)->startSession($mission, $operator);
        $observation = app(FieldMissionService::class)->submitObservation($session, [
            'schema_version' => 1,
            'stable_id' => 'field-client-observation-002',
            'claim_id' => $missionClaim->stable_id,
            'type' => 'BOARDING_LOCATION',
            'observed_at' => now()->subMinute()->toIso8601String(),
            'fact' => ['observed_precision' => 'BOARDING_AREA_OBSERVED', 'description' => 'Observed waiting area'],
        ], $operator)['observation'];

        $reviewer = User::factory()->create(['is_field_operator' => true]);
        app(FieldSurveyReviewService::class)->reviewObservation($observation, FieldObservationStatus::ACCEPTED_AS_EVIDENCE, $reviewer);
        $claim = app(FieldSurveyReviewService::class)->proposeClaim(
            FieldClaimTargetType::PATTERN_STOP,
            $missionClaim->target_stable_id,
            EvidenceClaimType::PATTERN_STOP_BOARDING,
            FieldClaimComparison::SUPPORTS_EXISTING,
            'The observed waiting area supports this mapped boarding association.',
            [$observation->id],
            $reviewer,
        );
        $attached = app(FieldSurveyReviewService::class)->attachClaim($claim, $reviewer);
        $preview = app(FieldPromotionService::class)->preview($attached);

        $this->assertTrue($preview['eligible']);
        $this->assertSame('VERIFIED', $preview['proposed_change']['boarding_verification_state']);
        $this->assertSame('UNVERIFIED', RoutePatternStop::query()->findOrFail($missionClaim->target_id)->boarding_verification_state->value);

        $action = app(FieldPromotionService::class)->promote($attached, $reviewer, 'Reviewed bounded field observation.');
        $this->assertSame('PROMOTION_APPLIED', $action->action);
        $this->assertSame('VERIFIED', RoutePatternStop::query()->findOrFail($missionClaim->target_id)->fresh()->boarding_verification_state->value);
        $this->assertDatabaseHas('field_review_actions', ['action' => 'PROMOTION_APPLIED']);
    }

    public function test_mission_api_requires_field_operator_and_does_not_expose_public_collection(): void
    {
        $this->seedTransportNetwork();
        [$operator] = $this->missionFixture();
        $this->getJson('/api/v1/field/missions')->assertUnauthorized();
        $this->actingAs($operator)->getJson('/api/v1/field/missions')->assertOk()->assertJsonPath('meta.location_tracking', 'EXPLICIT_PER_OBSERVATION_ONLY');
        $this->assertDatabaseCount('field_observations', 0);
    }

    public function test_campaign_replay_does_not_reopen_or_retarget_a_reviewed_claim(): void
    {
        $this->seedTransportNetwork();
        [, $mission, $missionClaim] = $this->missionFixture();
        $missionClaim->forceFill([
            'current_status' => 'ACCEPTED',
            'prompt' => 'Reviewed prompt retained after collection.',
            'metadata' => ['target_entity' => ['stop_name' => 'Reviewed stop'], 'review_note' => 'keep'],
        ])->save();

        $result = app(FieldMissionService::class)->syncCampaign([
            'campaign_version' => 'batch027-replay-test-v1',
            'promotion_gate' => ['automatic_promotions' => 0],
            'missions' => [[
                'id' => $mission->stable_id,
                'title' => 'Updated planning title',
                'start_area' => 'Carbon',
                'route_scope' => ['12L'],
                'claim_ids' => [$missionClaim->stable_id],
                'records_needed' => ['boarding'],
                'observe' => 'Observe the mapped boarding area.',
                'safety_notes' => 'Do not record unrelated movement.',
            ]],
            'claims' => [[
                'claim_id' => $missionClaim->stable_id,
                'claim_type' => 'BOARDING',
                'priority_score' => 99,
                'target_entity' => ['pattern_stable_id' => 'pattern-12l-forward', 'stop_stable_id' => 'stop-carbon'],
            ]],
        ]);

        $replayed = $missionClaim->fresh();
        $this->assertSame(['missions' => 0, 'claims' => 0], $result);
        $this->assertSame(1, FieldMissionClaim::query()->where('stable_id', $missionClaim->stable_id)->count());
        $this->assertSame('ACCEPTED', $replayed->current_status->value);
        $this->assertSame('Reviewed prompt retained after collection.', $replayed->prompt);
        $this->assertSame('Reviewed stop', $replayed->metadata['target_entity']['stop_name']);
        $this->assertSame('keep', $replayed->metadata['review_note']);
        $this->assertDatabaseCount('field_observations', 0);
    }

    /** @return array{0: User, 1: FieldMission, 2: FieldMissionClaim, 3: RoutePatternStop} */
    private function missionFixture(): array
    {
        $operator = User::factory()->create(['is_field_operator' => true]);
        $city = City::query()->where('stable_id', 'city-cebu-city')->firstOrFail();
        $patternStop = RoutePatternStop::query()->where('boarding_verification_state', 'UNVERIFIED')
            ->whereHas('routePattern', fn ($query) => $query->where('stable_id', 'pattern-12l-forward'))->firstOrFail();
        $mission = FieldMission::query()->create([
            'stable_id' => 'test-b020-carbon',
            'title' => 'Test Carbon mission',
            'area' => 'Carbon',
            'status' => 'PLANNED',
            'priority' => 20,
            'claim_checklist' => ['boarding'],
            'route_scope' => ['12L'],
            'instructions' => 'Synthetic test mission only.',
            'safety_notes' => 'Synthetic test only.',
            'is_synthetic' => false,
        ]);
        $missionClaim = FieldMissionClaim::query()->create([
            'stable_id' => 'test-b020-claim-board',
            'field_mission_id' => $mission->id,
            'claim_type' => 'BOARDING',
            'target_type' => 'route_pattern_stop',
            'target_id' => $patternStop->id,
            'target_stable_id' => $patternStop->stable_id,
            'prompt' => 'Synthetic boarding claim.',
            'current_status' => 'OPEN',
            'affected_od_pairs' => [],
            'journeys_affected' => 1,
            'priority_score' => 20,
            'metadata' => [
                'target_entity' => [
                    'pattern_stable_id' => $patternStop->routePattern->stable_id,
                    'stop_stable_id' => $patternStop->stop->stable_id,
                    'route_code' => '12L',
                    'direction' => $patternStop->routePattern->direction,
                    'stop_name' => $patternStop->stop->name,
                ],
            ],
        ]);

        return [$operator, $mission, $missionClaim, $patternStop];
    }

    private function seedTransportNetwork(): void
    {
        foreach (VehicleTypeCode::cases() as $type) {
            VehicleType::query()->create(['code' => $type->value, 'name' => $type->value, 'is_active' => true]);
        }
        $dataset = app(DatasetValidator::class)->validate(database_path('data/cebu-central-network-2026-10-05.json'));
        $this->assertTrue($dataset->isValid(), json_encode($dataset->errors()));
        app(DatasetImporter::class)->import($dataset->data ?? []);
    }
}
