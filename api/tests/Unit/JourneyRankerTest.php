<?php

namespace Tests\Unit;

use App\Enums\JourneyTrustState;
use App\Services\Routing\Journey;
use App\Services\Routing\JourneyRanker;
use Tests\TestCase;

class JourneyRankerTest extends TestCase
{
    public function test_ranking_is_trust_first_then_transfer_and_walking_burden(): void
    {
        $ranked = (new JourneyRanker)->rank([
            $this->journey('unverified-direct', JourneyTrustState::UNVERIFIED, 0, 10, 4),
            $this->journey('mixed-transfer', JourneyTrustState::MIXED, 1, 20, 3),
            $this->journey('mixed-direct', JourneyTrustState::MIXED, 0, 300, 8),
            $this->journey('verified-transfer', JourneyTrustState::VERIFIED, 1, 900, 8),
        ]);

        $this->assertSame(
            ['verified-transfer', 'mixed-direct', 'mixed-transfer', 'unverified-direct'],
            array_map(static fn (Journey $journey): string => $journey->id, $ranked),
        );
    }

    private function journey(string $id, JourneyTrustState $trustState, int $transfers, float $walking, int $stops): Journey
    {
        return new Journey(
            id: $id,
            origin: [],
            destination: [],
            legs: [],
            transferCount: $transfers,
            walkingDistanceMeters: $walking,
            rideStopCount: $stops,
            trustState: $trustState,
            provenance: [],
            warnings: [],
        );
    }
}
