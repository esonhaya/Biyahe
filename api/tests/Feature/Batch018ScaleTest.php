<?php

namespace Tests\Feature;

use App\Services\Evidence\NetworkIntelligenceAudit;
use App\Services\Routing\NetworkReleaseAudit;
use App\Services\Routing\ResumableCoverageAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch018ScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch018_matrix_is_explicit_and_bounded(): void
    {
        $matrix = NetworkReleaseAudit::matrix();
        $ids = (new ResumableCoverageAudit(app(\App\Services\Routing\JourneyPlanner::class)))->workUnits();

        $this->assertCount(47, $matrix);
        $this->assertCount(47, $ids);
        $this->assertCount(47, collect($ids)->pluck('id')->unique());
        $this->assertSame(2, config('biyahe.routing.public_max_transfers'));
        $this->assertLessThanOrEqual(20000, config('biyahe.routing.max_search_states'));
    }

    public function test_intelligence_is_a_derived_non_promoting_report(): void
    {
        $this->seed();

        $report = app(NetworkIntelligenceAudit::class)->analyze([
            'sample-pair' => [
                'status' => 'COMPLETE',
                'origin' => 'origin',
                'destination' => 'destination',
                'journey_refs' => [[
                    'rides' => [[
                        'pattern_id' => 1,
                        'route_id' => 1,
                        'boarding_stop_id' => 1,
                        'alighting_stop_id' => 2,
                        'boarding_sequence' => 3,
                        'alighting_sequence' => 8,
                    ]],
                ]],
            ],
        ], 10);

        $this->assertSame(0, $report['evidence_priorities']['claims_promoted']);
        $this->assertSame(1, $report['evidence_priorities']['audit_pairs_used']);
        $this->assertNotEmpty($report['evidence_priorities']['no_evidence_found']);
        $this->assertTrue(collect($report['evidence_priorities']['top_sequence'])
            ->contains(static fn (array $claim): bool => $claim['claim_type'] === 'SEQUENCE'));
        $this->assertTrue(collect($report['evidence_priorities']['field_survey_tasks'])
            ->every(static fn (array $task): bool => $task['is_evidence'] === false));
    }
}
