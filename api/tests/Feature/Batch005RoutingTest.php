<?php

namespace Tests\Feature;

use App\Enums\JourneyLegType;
use App\Enums\VerificationState;
use App\Models\Place;
use App\Models\RoutePattern;
use App\Models\Stop;
use App\Models\TransferRelationship;
use App\Services\Routing\NetworkAudit;
use App\Services\Routing\TransferCandidateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch005RoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_default_search_returns_bounded_two_transfer_candidates_without_loops(): void
    {
        $origin = Place::where('slug', 'cebu-it-park')->firstOrFail();
        $destination = Place::where('slug', 'sm-city-cebu')->firstOrFail();

        $response = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);

        $response->assertOk()->assertJsonPath('meta.max_transfers', 2);
        $journeys = $response->json('data');
        $this->assertNotEmpty($journeys);
        $this->assertSame(count($journeys), count(array_unique(array_column($journeys, 'id'))));
        $this->assertTrue(collect($journeys)->contains(fn (array $journey): bool => $journey['transfer_count'] === 2));

        foreach ($journeys as $journey) {
            $this->assertLessThanOrEqual(2, $journey['transfer_count']);
            $this->assertNotEmpty($journey['ranking']['reasons']);
            $rideLegs = collect($journey['legs'])->filter(fn (array $leg): bool => $leg['type'] === JourneyLegType::RIDE->value);
            $routeCodes = $rideLegs->pluck('route.code')->filter()->values()->all();
            $this->assertSame(count($routeCodes), count(array_unique($routeCodes)));

            foreach ($rideLegs as $rideLeg) {
                $this->assertSame($rideLeg['boarding_sequence'] + $rideLeg['stop_count'], $rideLeg['alighting_sequence']);
                $this->assertLessThan($rideLeg['alighting_sequence'], $rideLeg['boarding_sequence']);
                $this->assertNotContains($rideLeg['route']['verification']['state'], ['STALE', 'RETIRED']);
                $this->assertNotContains($rideLeg['pattern']['verification']['state'], ['STALE', 'RETIRED']);
            }
        }
    }

    public function test_public_transfer_cap_can_reduce_search_without_allowing_more_than_two(): void
    {
        $origin = Place::where('slug', 'cebu-it-park')->firstOrFail();
        $destination = Place::where('slug', 'sm-city-cebu')->firstOrFail();

        $oneTransfer = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id.'&max_transfers=1');
        $oneTransfer->assertOk()->assertJsonPath('meta.max_transfers', 1);
        foreach ($oneTransfer->json('data') as $journey) {
            $this->assertLessThanOrEqual(1, $journey['transfer_count']);
        }

        $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id.'&max_transfers=3')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_INPUT');
    }

    public function test_stale_intermediate_stops_cannot_leak_into_multi_transfer_results(): void
    {
        Stop::where('stable_id', 'stop-jy-square-mall')->update([
            'verification_state' => VerificationState::STALE->value,
        ]);
        $origin = Place::where('slug', 'cebu-it-park')->firstOrFail();
        $destination = Place::where('slug', 'sm-city-cebu')->firstOrFail();

        $response = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);

        $response->assertOk();
        foreach ($response->json('data') as $journey) {
            foreach ($journey['legs'] as $leg) {
                $this->assertNotSame('STALE', $leg['origin']['verification']['state'] ?? null);
                $this->assertNotSame('STALE', $leg['destination']['verification']['state'] ?? null);
            }
        }
    }

    public function test_can_i_ride_requires_directional_sequence_and_preserves_trust(): void
    {
        $origin = Place::where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::where('slug', 'carbon-market-unit-3')->firstOrFail();

        $yes = $this->getJson('/api/v1/can-i-ride?route_code=17B&from_place_id='.$origin->id.'&to_place_id='.$destination->id);
        $yes->assertOk()
            ->assertJsonPath('data.status', 'YES')
            ->assertJsonPath('data.option.pattern.direction', 'OUTBOUND');
        $yesOption = $yes->json('data.option');
        $this->assertLessThan($yesOption['alighting_sequence'], $yesOption['boarding_sequence']);
        $this->assertNotSame('VERIFIED', $yes->json('data.trust'));

        RoutePattern::where('stable_id', 'pattern-17b-forward')->update([
            'verification_state' => VerificationState::STALE->value,
        ]);

        $direction = $this->getJson('/api/v1/can-i-ride?route_code=17B&from_place_id='.$origin->id.'&to_place_id='.$destination->id);
        $direction->assertOk()
            ->assertJsonPath('data.status', 'DIRECTION_DEPENDENT')
            ->assertJsonPath('data.opposite_direction.pattern.direction', 'INBOUND');
        $this->assertNotSame('VERIFIED', $direction->json('data.trust'));
        $this->assertNotSame('STALE', $direction->json('data.opposite_direction.pattern.verification.state'));
    }

    public function test_can_i_ride_distinguishes_no_route_from_unknown_route(): void
    {
        $origin = Place::where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::where('slug', 'carbon-market-unit-3')->firstOrFail();

        $this->getJson('/api/v1/can-i-ride?route_code=04L&from_place_id='.$origin->id.'&to_place_id='.$destination->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'NO');

        $this->getJson('/api/v1/can-i-ride?route_code=NOT-A-ROUTE&from_place_id='.$origin->id.'&to_place_id='.$destination->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'UNKNOWN');
    }

    public function test_transfer_candidates_do_not_repeat_active_curated_relationships(): void
    {
        $reviewedPairs = TransferRelationship::query()
            ->where('is_active', true)
            ->where('is_demo', false)
            ->get()
            ->mapWithKeys(fn (TransferRelationship $relationship): array => [
                $relationship->from_stop_id.':'.$relationship->to_stop_id => true,
            ]);
        $candidates = app(TransferCandidateFinder::class)->find(
            cityId: Place::where('slug', 'lahug-market')->value('city_id'),
            limit: 200,
        );

        foreach ($candidates as $candidate) {
            $pair = $candidate['from_stop']['id'].':'.$candidate['to_stop']['id'];
            $this->assertFalse(isset($reviewedPairs[$pair]), 'An active curated transfer was returned as a new review candidate.');
        }
    }

    public function test_rejected_transfer_candidate_returns_after_its_network_fingerprint_changes(): void
    {
        $cityId = Place::where('slug', 'lahug-market')->value('city_id');
        $candidates = app(TransferCandidateFinder::class)->find(cityId: $cityId, limit: 200);
        $candidate = $candidates->first();
        $this->assertNotNull($candidate, 'The seeded network should provide a transfer-review candidate.');

        $relationship = TransferRelationship::create([
            'city_id' => $cityId,
            'from_stop_id' => $candidate['from_stop']['id'],
            'to_stop_id' => $candidate['to_stop']['id'],
            'stable_id' => 'test-rejected-transfer-candidate',
            'relationship_type' => 'WALK_TRANSFER',
            'distance_meters' => $candidate['distance_meters'],
            'review_status' => 'REJECTED',
            'review_notes' => 'No supported safe interchange is known for this pair.',
            'verification_state' => VerificationState::UNVERIFIED,
            'is_active' => true,
            'is_demo' => false,
        ]);
        $this->assertNotEmpty($relationship->review_fingerprint);

        $withoutRejectedPair = app(TransferCandidateFinder::class)->find(cityId: $cityId, limit: 200);
        $this->assertFalse($withoutRejectedPair->contains(fn (array $item): bool => $item['from_stop']['id'] === $candidate['from_stop']['id']
            && $item['to_stop']['id'] === $candidate['to_stop']['id']
        ));

        $stop = Stop::findOrFail($candidate['from_stop']['id']);
        $pattern = $stop->routePatterns()->firstOrFail();
        $pattern->update(['verification_state' => VerificationState::VERIFIED]);

        $afterNetworkChange = app(TransferCandidateFinder::class)->find(cityId: $cityId, limit: 200);
        $this->assertTrue($afterNetworkChange->contains(fn (array $item): bool => $item['from_stop']['id'] === $candidate['from_stop']['id']
            && $item['to_stop']['id'] === $candidate['to_stop']['id']
        ));
    }

    public function test_network_audit_reports_v5_metrics_without_integrity_errors(): void
    {
        $metrics = app(NetworkAudit::class)->audit(maxPlaces: 3, maxPairs: 3, maxCanIRideChecks: 2);
        $this->assertSame(2, $metrics['max_can_i_ride_checks']);
        $this->assertSame(2, $metrics['max_transfers']);
        $this->assertLessThanOrEqual(2, $metrics['can_i_ride_checked']);

        foreach ([
            'two_transfer',
            'average_transfers',
            'median_transfers',
            'can_i_ride_checked',
            'can_i_ride_yes',
            'can_i_ride_no',
            'can_i_ride_direction_dependent',
            'can_i_ride_unknown',
            'max_transfers',
            'max_can_i_ride_checks',
            'transfer_cap_violations',
            'direction_violations',
            'stale_leakage',
            'trust_leakage',
        ] as $metric) {
            $this->assertArrayHasKey($metric, $metrics);
        }
        $this->assertSame([], $metrics['errors']);
    }
}
