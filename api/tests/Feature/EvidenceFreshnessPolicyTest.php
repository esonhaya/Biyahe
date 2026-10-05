<?php

namespace Tests\Feature;

use App\Enums\EvidenceFreshnessState;
use App\Models\ProvenanceSource;
use App\Services\Evidence\EvidenceFreshnessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceFreshnessPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_current_aging_stale_and_unknown_boarding_evidence(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->startOfDay());
        $policy = app(EvidenceFreshnessPolicy::class);

        $current = ProvenanceSource::factory()->create(['checked_at' => '2026-10-04']);
        $aging = ProvenanceSource::factory()->create(['checked_at' => '2026-06-20']);
        $stale = ProvenanceSource::factory()->create(['checked_at' => '2026-03-01']);
        $unknown = ProvenanceSource::factory()->create(['checked_at' => null]);

        $this->assertSame(EvidenceFreshnessState::CURRENT, $policy->classify($current, 'BOARDING'));
        $this->assertSame(EvidenceFreshnessState::AGING, $policy->classify($aging, 'BOARDING'));
        $this->assertSame(EvidenceFreshnessState::STALE, $policy->classify($stale, 'BOARDING'));
        $this->assertSame(EvidenceFreshnessState::UNKNOWN_FRESHNESS, $policy->classify($unknown, 'BOARDING'));
    }

    public function test_superseded_or_conflicted_sources_cannot_support_a_verified_claim(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->startOfDay());
        $policy = app(EvidenceFreshnessPolicy::class);
        $replacement = ProvenanceSource::factory()->create(['checked_at' => '2026-10-05']);
        $superseded = ProvenanceSource::factory()->create([
            'checked_at' => '2026-10-05',
            'superseded_by_id' => $replacement->getKey(),
        ]);
        $conflicted = ProvenanceSource::factory()->create([
            'checked_at' => '2026-10-05',
            'conflict_status' => 'OPEN',
            'conflict_group' => 'route-identity-conflict',
        ]);

        $this->assertSame(EvidenceFreshnessState::STALE, $policy->classify($superseded, 'ROUTE_IDENTITY'));
        $this->assertFalse($policy->isUsableForVerifiedClaim($superseded, 'ROUTE_IDENTITY'));
        $this->assertFalse($policy->isUsableForVerifiedClaim($conflicted, 'ROUTE_IDENTITY'));
    }
}
