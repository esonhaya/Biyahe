<?php

namespace Tests\Feature;

use App\Enums\BoardingPrecision;
use App\Enums\VerificationState;
use App\Models\ProvenanceSource;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Support\Trust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimScopedTrustTest extends TestCase
{
    use RefreshDatabase;

    public function test_boarding_routability_does_not_depend_on_unused_alighting_fact(): void
    {
        $serviceSource = $this->source(['PATTERN_STOP_SERVICE']);
        $sequenceSource = $this->source(['PATTERN_STOP_SEQUENCE']);
        $boardingSource = $this->source(['PATTERN_STOP_BOARDING']);
        $association = new RoutePatternStop([
            'verification_state' => VerificationState::UNVERIFIED,
            'service_verification_state' => VerificationState::VERIFIED,
            'sequence_verification_state' => VerificationState::VERIFIED,
            'boarding_verification_state' => VerificationState::VERIFIED,
            'alighting_verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
            'is_active' => true,
        ]);
        $association->setRelation('serviceProvenanceSource', $serviceSource);
        $association->setRelation('sequenceProvenanceSource', $sequenceSource);
        $association->setRelation('boardingProvenanceSource', $boardingSource);

        $this->assertTrue(Trust::isFactRoutable($association, ['service', 'sequence', 'boarding']));
        $this->assertFalse(Trust::isFactRoutable($association, ['service', 'sequence', 'alighting']));
        $this->assertSame(VerificationState::VERIFIED->value, Trust::effectiveFactSetState($association, ['service', 'sequence', 'boarding']));
    }

    public function test_stop_location_verification_is_independent_from_unknown_boarding_precision(): void
    {
        $locationSource = $this->source(['STOP_LOCATION']);
        $boardingSource = $this->source(['BOARDING']);
        $stop = new Stop([
            'verification_state' => VerificationState::VERIFIED,
            'is_demo' => false,
            'is_active' => true,
            'is_boarding_point' => true,
            'boarding_precision' => BoardingPrecision::UNKNOWN,
            'boarding_verification_state' => VerificationState::VERIFIED,
        ]);
        $stop->setRelation('provenanceSource', $locationSource);
        $stop->setRelation('boardingProvenanceSource', $boardingSource);

        $this->assertSame(VerificationState::VERIFIED->value, Trust::effectiveState($stop));
        $this->assertSame(VerificationState::UNVERIFIED->value, Trust::effectiveFactState($stop, 'boarding'));
        $this->assertTrue(Trust::isRoutable($stop));
    }

    public function test_verified_pattern_stop_fact_without_matching_scope_is_downgraded(): void
    {
        $source = $this->source(['ROUTE_IDENTITY']);
        $association = new RoutePatternStop([
            'verification_state' => VerificationState::VERIFIED,
            'service_verification_state' => VerificationState::VERIFIED,
            'is_demo' => false,
            'is_active' => true,
        ]);
        $association->setRelation('serviceProvenanceSource', $source);

        $this->assertSame(VerificationState::UNVERIFIED->value, Trust::effectiveFactState($association, 'service'));
    }

    /** @param list<string> $scopes */
    private function source(array $scopes): ProvenanceSource
    {
        return ProvenanceSource::factory()->create([
            'source_type' => 'OPERATOR',
            'checked_at' => now()->toDateString(),
            'evidence_scope' => $scopes,
            'conflict_status' => 'NONE',
        ]);
    }
}
