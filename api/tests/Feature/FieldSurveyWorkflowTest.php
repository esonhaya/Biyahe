<?php

namespace Tests\Feature;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimStatus;
use App\Enums\FieldClaimTargetType;
use App\Enums\FieldObservationStatus;
use App\Enums\SurveySessionStatus;
use App\Enums\VehicleTypeCode;
use App\Models\City;
use App\Models\Fare;
use App\Models\FieldObservation;
use App\Models\ProvenanceSource;
use App\Models\RoutePatternStop;
use App\Models\SurveyTracePoint;
use App\Models\TransportSurveySession;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Data\DatasetImporter;
use App\Services\Data\DatasetValidator;
use App\Services\FieldSurveys\FieldSurveyReport;
use App\Services\FieldSurveys\FieldSurveyReviewService;
use App\Services\FieldSurveys\FieldSurveyTraceReviewData;
use App\Services\FieldSurveys\SurveyImportConflict;
use App\Services\FieldSurveys\SurveyImporter;
use App\Services\FieldSurveys\SurveyImportValidator;
use App\Support\Trust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldSurveyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_package_validates_imports_idempotently_and_persists_postgis_points(): void
    {
        $this->seedTransportNetwork();
        $path = $this->writeSurveyPackage([$this->survey('survey-test-offline')]);

        try {
            $validation = app(SurveyImportValidator::class)->validate($path);
            $this->assertTrue($validation->isValid(), json_encode($validation->errors()));
            $first = app(SurveyImporter::class)->import($validation, synthetic: true);
            TransportSurveySession::query()->where('stable_id', 'survey-test-offline')->update(['status' => 'UNDER_REVIEW']);
            $second = app(SurveyImporter::class)->import($validation, synthetic: true);

            $this->assertSame(1, $first->counts()['surveys']['imported']);
            $this->assertSame(1, $first->counts()['observations']['imported']);
            $this->assertSame(1, $first->counts()['trace_points']['imported']);
            $this->assertSame(1, $second->counts()['surveys']['skipped']);
            $this->assertSame(1, $second->counts()['observations']['skipped']);
            $this->assertSame(1, $second->counts()['trace_points']['skipped']);
            $observation = FieldObservation::query()
                ->when(config('database.default') === 'pgsql', fn ($query) => $query->addSelect(['field_observations.*', \DB::raw('ST_AsText(field_observations.location) AS location_wkt')]))
                ->where('stable_id', 'observation-survey-test-offline-01')
                ->firstOrFail();
            $this->assertSame(FieldObservationStatus::SUBMITTED, $observation->review_status);
            $this->assertSame(123.8854, round((float) $observation->location_point['lng'], 4));
            $this->assertSame(10.3157, round((float) $observation->location_point['lat'], 4));
            $this->assertTrue($observation->surveySession->is_synthetic);
            $this->assertSame('UNDER_REVIEW', $observation->surveySession->status->value);
            $this->assertSame('UNKNOWN', $observation->location_precision->value);

            $traceReview = app(FieldSurveyTraceReviewData::class)->forSession($observation->surveySession);
            $this->assertNotEmpty($traceReview['route_lines']);
            $this->assertContains('stop-ayala-center-terminal', array_column($traceReview['mapped_stops'], 'stable_id'));
            $this->assertSame([], $traceReview['potential_jumps']);
            SurveyTracePoint::factory()->create([
                'survey_session_id' => $observation->survey_session_id,
                'sequence' => 2,
                'observed_at' => now()->subMinutes(7),
                'location' => ['lat' => 10.3157, 'lng' => 124.0],
                'reported_accuracy_m' => 2,
            ]);
            $traceReview = app(FieldSurveyTraceReviewData::class)->forSession($observation->surveySession->fresh());
            $this->assertSame(1, count($traceReview['potential_jumps']));

            if (config('database.default') === 'pgsql') {
                $point = \DB::selectOne('SELECT ST_SRID(location::geometry) AS srid, ST_X(location::geometry) AS longitude, ST_Y(location::geometry) AS latitude FROM field_observations WHERE id = ?', [$observation->id]);
                $this->assertSame(4326, (int) $point->srid);
                $this->assertEqualsWithDelta(123.8854, (float) $point->longitude, 0.000001);
                $this->assertEqualsWithDelta(10.3157, (float) $point->latitude, 0.000001);
                $trace = SurveyTracePoint::query()->where('stable_id', 'trace-survey-test-offline-01')->firstOrFail();
                $this->assertSame(4326, (int) \DB::table('survey_trace_points')->where('id', $trace->id)->selectRaw('ST_SRID(location::geometry) AS srid')->value('srid'));
            }
        } finally {
            unlink($path);
        }
    }

    public function test_dry_run_and_malformed_coordinates_never_write_survey_rows(): void
    {
        $this->seedTransportNetwork();
        $survey = $this->survey('survey-test-dry-run');
        $path = $this->writeSurveyPackage([$survey]);

        try {
            $this->artisan('biyahe:surveys:import', ['dataset' => $path, '--dry-run' => true])
                ->expectsOutputToContain('no survey records were persisted')
                ->assertExitCode(0);
            $this->assertDatabaseCount('transport_survey_sessions', 0);

            $survey['observations'][0]['location']['coordinates'] = [10.3157, 123.8854];
            file_put_contents($path, json_encode(['schema_version' => 1, 'surveys' => [$survey]], JSON_THROW_ON_ERROR));
            $this->artisan('biyahe:surveys:validate', ['dataset' => $path])->assertExitCode(1);
            $this->assertDatabaseCount('transport_survey_sessions', 0);

            $survey['observations'][0]['location']['coordinates'] = [123.8854, 10.3157];
            $survey['observations'][0]['location_precision'] = 'EXACT';
            file_put_contents($path, json_encode(['schema_version' => 1, 'surveys' => [$survey]], JSON_THROW_ON_ERROR));
            $precisionValidation = app(SurveyImportValidator::class)->validate($path);
            $this->assertFalse($precisionValidation->isValid());
            $this->assertTrue(collect($precisionValidation->errors())->contains(fn (array $error): bool => str_contains($error['path'], 'location_precision')));
            $this->assertDatabaseCount('transport_survey_sessions', 0);

            $survey = $this->survey('survey-test-bad-trace-order');
            $survey['trace_points'][] = [
                'stable_id' => 'trace-survey-test-bad-trace-order-02',
                'sequence' => 2,
                'observed_at' => now()->subMinutes(9)->toIso8601String(),
                'location' => ['type' => 'Point', 'coordinates' => [123.886, 10.316]],
                'location_method' => 'DEVICE',
            ];
            file_put_contents($path, json_encode(['schema_version' => 1, 'surveys' => [$survey]], JSON_THROW_ON_ERROR));
            $traceValidation = app(SurveyImportValidator::class)->validate($path);
            $this->assertFalse($traceValidation->isValid());
            $this->assertTrue(collect($traceValidation->errors())->contains(fn (array $error): bool => str_contains($error['message'], 'must not go backwards')));
            $this->assertDatabaseCount('survey_trace_points', 0);

            $plannedWithObservation = $this->survey('survey-test-planned-with-observation');
            $plannedWithObservation['status'] = 'PLANNED';
            unset($plannedWithObservation['started_at'], $plannedWithObservation['ended_at']);
            file_put_contents($path, json_encode(['schema_version' => 1, 'surveys' => [$plannedWithObservation]], JSON_THROW_ON_ERROR));
            $plannedValidation = app(SurveyImportValidator::class)->validate($path);
            $this->assertFalse($plannedValidation->isValid());
            $this->assertTrue(collect($plannedValidation->errors())->contains(fn (array $error): bool => str_contains($error['message'], 'planned survey cannot contain')));
            $this->assertDatabaseCount('transport_survey_sessions', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_offline_planned_package_can_be_completed_without_reverting_review_state(): void
    {
        $this->seedTransportNetwork();
        $planned = $this->survey('survey-test-planned-progress');
        $planned['status'] = 'PLANNED';
        unset($planned['started_at'], $planned['ended_at']);
        $planned['observations'] = [];
        $planned['trace_points'] = [];
        $planned['observer_reference'] = 'REPLACE_WITH_ACCOUNTABLE_OBSERVER_REFERENCE';
        $plannedPath = $this->writeSurveyPackage([$planned]);
        $completedPath = $this->writeSurveyPackage([$this->survey('survey-test-planned-progress')]);

        try {
            $validator = app(SurveyImportValidator::class);
            $this->assertFalse($validator->validate($plannedPath)->isValid());
            $planned['observer_reference'] = 'SYNTHETIC TEST FIXTURE — no real field survey';
            file_put_contents($plannedPath, json_encode(['schema_version' => 1, 'surveys' => [$planned]], JSON_THROW_ON_ERROR));
            app(SurveyImporter::class)->import($validator->validate($plannedPath), synthetic: true);
            $this->assertDatabaseHas('transport_survey_sessions', [
                'stable_id' => 'survey-test-planned-progress',
                'status' => 'PLANNED',
            ]);

            app(SurveyImporter::class)->import($validator->validate($completedPath), synthetic: true);
            $this->assertDatabaseHas('transport_survey_sessions', [
                'stable_id' => 'survey-test-planned-progress',
                'status' => 'COMPLETED',
            ]);
            $this->assertDatabaseHas('field_observations', [
                'stable_id' => 'observation-survey-test-planned-progress-01',
                'review_status' => 'SUBMITTED',
            ]);

            TransportSurveySession::query()->where('stable_id', 'survey-test-planned-progress')->update(['status' => 'UNDER_REVIEW']);
            app(SurveyImporter::class)->import($validator->validate($completedPath), synthetic: true);
            $this->assertDatabaseHas('transport_survey_sessions', [
                'stable_id' => 'survey-test-planned-progress',
                'status' => 'UNDER_REVIEW',
            ]);
        } finally {
            unlink($plannedPath);
            unlink($completedPath);
        }
    }

    public function test_negative_service_and_paid_fare_are_observations_not_transport_or_fare_facts(): void
    {
        $this->seedTransportNetwork();
        $fareCount = Fare::query()->count();
        $survey = $this->survey('survey-test-negative-and-fare');
        $survey['observations'] = [
            [
                'stable_id' => 'observation-negative-service',
                'sequence' => 1,
                'type' => 'SERVICE_NOT_OBSERVED',
                'observed_at' => now()->subMinutes(8)->toIso8601String(),
                'route_id' => 'route-12l',
                'location' => ['type' => 'Point', 'coordinates' => [123.8854, 10.3157]],
                'location_method' => 'MANUAL',
                'window_minutes' => 15,
                'fact' => ['expected_service' => '12L'],
                'notes' => 'SYNTHETIC TEST ONLY: no service was physically observed.',
            ],
            [
                'stable_id' => 'observation-fare-paid',
                'sequence' => 2,
                'type' => 'FARE_PAID',
                'observed_at' => now()->subMinutes(6)->toIso8601String(),
                'route_id' => 'route-12l',
                'fare_from_stop_id' => 'stop-ayala-center-terminal',
                'fare_to_stop_id' => 'stop-labangon',
                'fact' => ['amount' => 18.5, 'currency' => 'PHP', 'passenger_class' => 'regular'],
                'notes' => 'SYNTHETIC TEST ONLY: no fare was physically paid.',
            ],
        ];
        $path = $this->writeSurveyPackage([$survey]);

        try {
            $validation = app(SurveyImportValidator::class)->validate($path);
            $this->assertTrue($validation->isValid(), json_encode($validation->errors()));
            app(SurveyImporter::class)->import($validation, synthetic: true);

            $negative = FieldObservation::query()->where('stable_id', 'observation-negative-service')->firstOrFail();
            $fare = FieldObservation::query()->where('stable_id', 'observation-fare-paid')->firstOrFail();
            $this->assertSame(15, (int) $negative->observation_window_minutes);
            $this->assertSame('18.50', $fare->fare_amount);
            $this->assertSame($fareCount, Fare::query()->count());
            $this->assertSame(0, app(FieldSurveyReport::class)->report()['observations_total']);
        } finally {
            unlink($path);
        }
    }

    public function test_import_conflict_rolls_back_earlier_new_session_in_same_package(): void
    {
        $this->seedTransportNetwork();
        $importer = app(SurveyImporter::class);
        $validator = app(SurveyImportValidator::class);
        $existing = $this->survey('survey-existing');
        $initialPath = $this->writeSurveyPackage([$existing]);
        $conflictingPath = $this->writeSurveyPackage([
            $this->survey('survey-new-before-conflict', 'A new survey'),
            $this->survey('survey-existing', 'Different content under a reused stable ID'),
        ]);

        try {
            $importer->import($validator->validate($initialPath), synthetic: true);
            $this->artisan('biyahe:surveys:import', ['dataset' => $conflictingPath, '--dry-run' => true])
                ->expectsOutputToContain('rolled back')
                ->assertExitCode(1);
            $this->assertDatabaseMissing('transport_survey_sessions', ['stable_id' => 'survey-new-before-conflict']);

            try {
                $importer->import($validator->validate($conflictingPath), synthetic: true);
                $this->fail('Conflicting stable content must reject the complete import.');
            } catch (SurveyImportConflict) {
                $this->assertDatabaseMissing('transport_survey_sessions', ['stable_id' => 'survey-new-before-conflict']);
            }
        } finally {
            unlink($initialPath);
            unlink($conflictingPath);
        }
    }

    public function test_reviewed_field_claim_attaches_scoped_provenance_without_promoting_fact_trust(): void
    {
        $this->seedTransportNetwork();
        $target = RoutePatternStop::query()->with('routePattern.route')->where('stable_id', 'pattern-12l-forward-stop-01')->firstOrFail();
        $initialTrust = Trust::effectiveFactState($target->load('sequenceProvenanceSource'), 'sequence');
        $initialState = $target->sequence_verification_state->value;
        $initialSource = $target->sequence_provenance_source_id;
        $firstObserver = User::factory()->create();
        $session = TransportSurveySession::factory()->create([
            'stable_id' => 'test-only-survey-claim',
            'city_id' => $target->routePattern->route->city_id,
            'route_id' => $target->routePattern->route_id,
            'route_pattern_id' => $target->route_pattern_id,
            'status' => 'COMPLETED',
            'is_synthetic' => false,
            'observer_user_id' => $firstObserver->id,
            'observer_reference' => 'SYNTHETIC TEST FIXTURE — no real ride occurred',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
        $secondObserver = User::factory()->create();
        $secondSession = TransportSurveySession::factory()->create([
            'stable_id' => 'test-only-survey-claim-second',
            'city_id' => $target->routePattern->route->city_id,
            'route_id' => $target->routePattern->route_id,
            'route_pattern_id' => $target->route_pattern_id,
            'status' => 'COMPLETED',
            'is_synthetic' => false,
            'observer_user_id' => $secondObserver->id,
            'observer_reference' => 'SYNTHETIC TEST FIXTURE — second observer, no real ride',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);
        $observation = FieldObservation::factory()->create([
            'stable_id' => 'test-only-observation-claim',
            'survey_session_id' => $session->id,
            'review_status' => FieldObservationStatus::SUBMITTED,
            'observed_fact' => ['description' => 'Synthetic integration fixture; no actual transport observation.'],
        ]);
        $reviewer = User::factory()->create();
        $service = app(FieldSurveyReviewService::class);
        $service->reviewObservation($observation, FieldObservationStatus::ACCEPTED_AS_EVIDENCE, $reviewer);
        $secondObservation = FieldObservation::factory()->create([
            'stable_id' => 'test-only-observation-claim-second',
            'survey_session_id' => $secondSession->id,
            'review_status' => FieldObservationStatus::SUBMITTED,
            'observed_fact' => ['description' => 'Second synthetic integration fixture; no actual transport observation.'],
        ]);
        $service->reviewObservation($secondObservation, FieldObservationStatus::ACCEPTED_AS_EVIDENCE, $secondObserver);
        $target->routePattern->forceFill(['is_demo' => true])->save();
        try {
            $service->proposeClaim(
                FieldClaimTargetType::PATTERN_STOP,
                $target->stable_id,
                EvidenceClaimType::PATTERN_STOP_SEQUENCE,
                FieldClaimComparison::SUPPORTS_EXISTING,
                'Must not attach field evidence to demo service data.',
                [$observation->id],
                $reviewer,
            );
            $this->fail('Claims must not attach to demo transport records.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target', $exception->errors());
        } finally {
            $target->routePattern->forceFill(['is_demo' => false])->save();
        }
        $supportingSource = ProvenanceSource::factory()->create([
            'source_type' => 'OFFICIAL',
            'title' => 'Synthetic integration-only source',
            'checked_at' => now()->toDateString(),
            'evidence_scope' => ['PATTERN_STOP_SEQUENCE'],
            'conflict_status' => 'NONE',
        ]);
        $claim = $service->proposeClaim(
            FieldClaimTargetType::PATTERN_STOP,
            $target->stable_id,
            EvidenceClaimType::PATTERN_STOP_SEQUENCE,
            FieldClaimComparison::SUPPORTS_EXISTING,
            'Synthetic test-only fact claim; no production fact is asserted.',
            [$observation->id, $secondObservation->id],
            $reviewer,
            [$supportingSource->id],
        );
        $attached = $service->attachClaim($claim, $reviewer);
        $targetAfter = RoutePatternStop::query()->with('sequenceProvenanceSource')->findOrFail($target->id);

        $this->assertSame(FieldClaimStatus::ATTACHED, $attached->review_status);
        $this->assertSame(['PATTERN_STOP_SEQUENCE'], $attached->provenanceSource->evidence_scope);
        $this->assertSame(2, $attached->observations->count());
        $this->assertCount(2, $attached->supportingSources);
        $this->assertSame($initialState, $targetAfter->sequence_verification_state->value);
        $this->assertSame($initialSource, $targetAfter->sequence_provenance_source_id);
        $this->assertSame($initialTrust, Trust::effectiveFactState($targetAfter, 'sequence'));
        $attached->load('observations.surveySession');
        $this->assertSame(2, $attached->observations->pluck('surveySession.observer_user_id')->unique()->count());
        $reportBeforeSyntheticMark = app(FieldSurveyReport::class)->report();
        $this->assertSame(1, $reportBeforeSyntheticMark['facts_with_multiple_field_observations']);
        $this->assertSame(2, $reportBeforeSyntheticMark['claims'][0]['independent_observers']);
        $this->assertSame('REAL_ONLY', $reportBeforeSyntheticMark['metric_scope']);
        $this->assertSame(0, $reportBeforeSyntheticMark['synthetic_metrics']['surveys_total']);
        $this->assertStringNotContainsString('SYNTHETIC TEST FIXTURE', json_encode($reportBeforeSyntheticMark, JSON_THROW_ON_ERROR));
        $priorityReport = app(FieldSurveyReport::class)->report(['items' => [
            ['record_type' => 'transport_model', 'stable_id' => 'pattern-12l-forward'],
            ['record_type' => 'route_pattern_stop', 'stable_id' => $target->stable_id, 'fact' => 'sequence'],
        ]]);
        $this->assertSame(2, $priorityReport['survey_priority_items']);
        $this->assertSame(1, $priorityReport['high_impact_unsurveyed_fact_count']);
        $this->assertSame('pattern-12l-forward', $priorityReport['high_impact_unsurveyed_facts'][0]['stable_id']);

        TransportSurveySession::query()->whereIn('id', [$session->id, $secondSession->id])->update(['is_synthetic' => true]);
        $report = app(FieldSurveyReport::class)->report();
        $this->assertSame(0, $report['surveys_total']);
        $this->assertSame(0, $report['observations_total']);
        $this->assertSame(0, $report['field_claims_total']);
        $this->assertSame(2, $report['synthetic_metrics']['surveys_total']);
        $this->assertSame(2, $report['synthetic_metrics']['observations_total']);
        $this->assertSame(2, $report['synthetic_metrics']['observations_accepted']);
        $this->assertSame(1, $report['synthetic_metrics']['claims_with_synthetic_observations']);
    }

    public function test_synthetic_factory_evidence_is_excluded_and_cannot_be_reviewed_or_claimed(): void
    {
        $session = TransportSurveySession::factory()->create();
        $observation = FieldObservation::factory()->create(['survey_session_id' => $session->id]);
        $this->assertTrue($session->is_synthetic);
        $this->assertSame(0, app(FieldSurveyReport::class)->report()['surveys_total']);
        $this->assertSame(0, app(FieldSurveyReport::class)->report()['observations_total']);

        try {
            app(FieldSurveyReviewService::class)->reviewObservation(
                $observation,
                FieldObservationStatus::ACCEPTED_AS_EVIDENCE,
                User::factory()->create(),
            );
            $this->fail('Synthetic observations must not enter the evidence review lifecycle.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('observation', $exception->errors());
        }

        try {
            app(FieldSurveyReviewService::class)->updateSessionStatus(
                $session,
                SurveySessionStatus::IN_PROGRESS,
                User::factory()->create(),
            );
            $this->fail('Synthetic sessions must not enter the production review lifecycle.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('session', $exception->errors());
        }

        $this->getJson('/api/v1/field-observations')->assertNotFound();
        $this->assertArrayNotHasKey('notes', $observation->toArray());
        $this->assertArrayNotHasKey('observed_fact', $observation->toArray());
        $this->assertArrayNotHasKey('location', $observation->toArray());
    }

    public function test_authenticated_admin_surfaces_are_not_public_and_empty_template_is_valid(): void
    {
        foreach (['/admin/transport-survey-sessions', '/admin/field-observations', '/admin/field-evidence-claims'] as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->assertTrue(app(SurveyImportValidator::class)
            ->validate(database_path('data/field-survey-package-v1.template.json'))
            ->isValid());
    }

    public function test_priority_campaign_contains_only_unobserved_planned_sessions(): void
    {
        $this->seedTransportNetwork();
        $path = database_path('data/field-survey-priority-package-2026-10-06.json');
        $package = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $surveys = $package['surveys'];

        $this->assertSame(1, $package['schema_version']);
        $this->assertSame([
            'planned-pattern-12l-forward',
            'planned-pattern-17b-forward',
            'planned-transfer-transfer-b-rodriguez-to-fuente',
            'planned-transfer-transfer-fuente-to-b-rodriguez',
        ], array_column($surveys, 'stable_id'));
        foreach ($surveys as $survey) {
            $this->assertSame('PLANNED', $survey['status']);
            $this->assertSame([], $survey['observations']);
            $this->assertSame([], $survey['trace_points']);
            $this->assertArrayNotHasKey('started_at', $survey);
            $this->assertArrayNotHasKey('ended_at', $survey);
        }

        $validation = app(SurveyImportValidator::class)->validate($path);
        $this->assertFalse($validation->isValid());
        $this->assertCount(4, $validation->errors());
        $this->assertSame(0, TransportSurveySession::query()->count());
        $this->assertSame(0, FieldObservation::query()->count());
    }

    public function test_aborted_package_accepts_no_collection_and_rejects_partial_times(): void
    {
        $this->seedTransportNetwork();
        $survey = $this->survey('survey-test-aborted-before-start');
        $survey['status'] = 'ABORTED';
        $survey['observations'] = [];
        $survey['trace_points'] = [];
        unset($survey['started_at'], $survey['ended_at']);
        $path = $this->writeSurveyPackage([$survey]);

        try {
            $this->assertTrue(app(SurveyImportValidator::class)->validate($path)->isValid());
            $survey['ended_at'] = now()->toIso8601String();
            file_put_contents($path, json_encode(['schema_version' => 1, 'surveys' => [$survey]], JSON_THROW_ON_ERROR));
            $this->assertFalse(app(SurveyImportValidator::class)->validate($path)->isValid());
        } finally {
            unlink($path);
        }
    }

    public function test_review_service_sets_collection_bounds_and_allows_prestart_abort(): void
    {
        $this->seedTransportNetwork();
        $cebuCityId = City::query()->where('stable_id', 'city-cebu-city')->value('id');
        $observer = User::factory()->create();
        $session = TransportSurveySession::factory()->create([
            'stable_id' => 'test-only-session-lifecycle',
            'city_id' => $cebuCityId,
            'is_synthetic' => false,
        ]);
        $service = app(FieldSurveyReviewService::class);

        $started = $service->updateSessionStatus($session, SurveySessionStatus::IN_PROGRESS, $observer);
        $this->assertNotNull($started->started_at);
        $this->assertNull($started->ended_at);
        $this->assertSame($observer->id, $started->observer_user_id);
        $this->assertNull($started->reviewed_by_user_id);

        $completed = $service->updateSessionStatus($started, SurveySessionStatus::COMPLETED, $observer);
        $this->assertNotNull($completed->started_at);
        $this->assertNotNull($completed->ended_at);
        $this->assertGreaterThanOrEqual($completed->started_at, $completed->ended_at);

        $planned = TransportSurveySession::factory()->create([
            'stable_id' => 'test-only-session-aborted-before-start',
            'city_id' => $cebuCityId,
            'is_synthetic' => false,
        ]);
        $aborted = $service->updateSessionStatus($planned, SurveySessionStatus::ABORTED, $observer);
        $this->assertNull($aborted->started_at);
        $this->assertNull($aborted->ended_at);
    }

    /** @return array<string, mixed> */
    private function survey(string $stableId, string $title = 'Synthetic integration package'): array
    {
        $start = now()->subMinutes(10)->toIso8601String();
        $end = now()->subMinutes(2)->toIso8601String();

        return [
            'stable_id' => $stableId,
            'title' => $title,
            'purpose' => 'SYNTHETIC TEST ONLY: exercise offline field package import.',
            'city_id' => 'city-cebu-city',
            'route_id' => 'route-12l',
            'route_pattern_id' => 'pattern-12l-forward',
            'observer_reference' => 'SYNTHETIC TEST FIXTURE — no real field survey',
            'status' => 'COMPLETED',
            'started_at' => $start,
            'ended_at' => $end,
            'notes' => 'Synthetic integration fixture. Not a real observation.',
            'observations' => [[
                'stable_id' => 'observation-'.$stableId.'-01',
                'sequence' => 1,
                'type' => 'VEHICLE_BOARDED',
                'observed_at' => now()->subMinutes(8)->toIso8601String(),
                'route_id' => 'route-12l',
                'route_pattern_id' => 'pattern-12l-forward',
                'stop_id' => 'stop-ayala-center-terminal',
                'location' => ['type' => 'Point', 'coordinates' => [123.8854, 10.3157]],
                'location_method' => 'DEVICE',
                'accuracy_m' => 7.5,
                'fact' => ['route_code' => '12L', 'cue' => 'SYNTHETIC TEST ONLY'],
                'notes' => 'Synthetic fixture only.',
                'supporting_references' => [[
                    'type' => 'OTHER',
                    'title' => 'Synthetic test reference',
                    'reference' => 'ref:synthetic-test',
                ]],
            ]],
            'trace_points' => [[
                'stable_id' => 'trace-'.$stableId.'-01',
                'sequence' => 1,
                'observed_at' => now()->subMinutes(8)->toIso8601String(),
                'location' => ['type' => 'Point', 'coordinates' => [123.8854, 10.3157]],
                'location_method' => 'DEVICE',
                'accuracy_m' => 7.5,
            ]],
        ];
    }

    /** @param list<array<string, mixed>> $surveys */
    private function writeSurveyPackage(array $surveys): string
    {
        $path = tempnam(sys_get_temp_dir(), 'biyahe-survey-');
        file_put_contents($path, json_encode(['schema_version' => 1, 'surveys' => $surveys], JSON_THROW_ON_ERROR));

        return $path;
    }

    private function seedTransportNetwork(): void
    {
        foreach (VehicleTypeCode::cases() as $type) {
            VehicleType::query()->create([
                'code' => $type->value,
                'name' => $type->value,
                'is_active' => true,
            ]);
        }
        $dataset = app(DatasetValidator::class)->validate(database_path('data/cebu-central-network-2026-10-05.json'));
        $this->assertTrue($dataset->isValid(), json_encode($dataset->errors()));
        app(DatasetImporter::class)->import($dataset->data ?? []);
    }
}
