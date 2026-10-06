<?php

namespace Tests\Feature;

use App\Enums\VerificationState;
use App\Models\Place;
use App\Models\RoutePattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JourneyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_direct_journey_respects_direction_and_stop_sequence(): void
    {
        $response = $this->journey('lahug-market', 'carbon-market-unit-3');
        $response->assertOk()->assertJsonPath('meta.no_route', null);

        $journey = collect($response->json('data'))->first(fn (array $candidate): bool => $candidate['transfer_count'] === 0);
        $this->assertNotNull($journey);

        $rideLeg = collect($journey['legs'])->firstWhere('type', 'RIDE');
        $this->assertSame('17B', $rideLeg['route']['code']);
        $this->assertSame('OUTBOUND', $rideLeg['pattern']['direction']);
        $this->assertSame('JY Square Mall', $rideLeg['boarding_stop']['name']);
        $this->assertSame('Carbon Market Unit 3', $rideLeg['alighting_stop']['name']);
        $this->assertSame(6, $rideLeg['stop_count']);
        $this->assertSame('MIXED', $journey['trust']['state']);
        $this->assertSame('UNAVAILABLE', $journey['fare']['status']);
        $this->assertStringContainsString('Tell the driver', $rideLeg['driver_instruction']);
        $this->assertSame('UNKNOWN', $rideLeg['alighting_stop']['alighting_precision']);

        $accessLeg = $journey['legs'][0];
        $this->assertSame('STRAIGHT_LINE_ESTIMATE', $accessLeg['distance_type']);
        $this->assertSame('UNAVAILABLE', $accessLeg['routing_status']);
        $this->assertNull($accessLeg['geometry']);
        $this->assertNull($accessLeg['estimated_duration_seconds']);
        $this->assertStringContainsString('Straight-line estimate', $accessLeg['instruction']);
        $this->assertSame('straight_line_estimate', $response->json('meta.walking_distance_type'));
    }

    public function test_can_i_ride_exposes_access_estimates_without_changing_transport_decision(): void
    {
        $origin = Place::where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::where('slug', 'carbon-market-unit-3')->firstOrFail();

        $response = $this->getJson('/api/v1/can-i-ride?route_code=17B&from_place_id='.$origin->id.'&to_place_id='.$destination->id);
        $response->assertOk()->assertJsonPath('data.status', 'YES');
        $this->assertSame('STRAIGHT_LINE_ESTIMATE', $response->json('data.option.pedestrian_access.to_boarding.distance_type'));
        $this->assertNull($response->json('data.option.pedestrian_access.to_boarding.geometry'));
        $this->assertSame('UNKNOWN', $response->json('data.option.alighting_stop.alighting_precision'));
    }

    public function test_reverse_journey_uses_the_opposite_pattern_not_a_backwards_ride(): void
    {
        $response = $this->journey('carbon-market-unit-3', 'lahug-market');
        $response->assertOk();

        $journey = collect($response->json('data'))->firstWhere('transfer_count', 0);
        $rideLeg = collect($journey['legs'])->firstWhere('type', 'RIDE');

        $this->assertSame('INBOUND', $rideLeg['pattern']['direction']);
        $this->assertSame('Carbon Market Unit 3', $rideLeg['boarding_stop']['name']);
        $this->assertSame('JY Square Mall', $rideLeg['alighting_stop']['name']);
    }

    public function test_one_transfer_journey_uses_the_curated_bounded_transfer(): void
    {
        $response = $this->journey('ayala-center-cebu', 'carbon-market-unit-3');
        $response->assertOk()->assertJsonPath('meta.no_route', null);

        $journey = collect($response->json('data'))->firstWhere('transfer_count', 1);
        $this->assertNotNull($journey);
        $this->assertSame(
            ['WALK_ACCESS', 'RIDE', 'TRANSFER_WALK', 'RIDE', 'WALK_EGRESS'],
            array_column($journey['legs'], 'type'),
        );
        $this->assertLessThanOrEqual(600, $journey['legs'][2]['distance_meters']);
        $this->assertNotNull($journey['legs'][1]['route']['code']);
        $this->assertNotNull($journey['legs'][3]['route']['code']);
        $this->assertNotSame($journey['legs'][1]['route']['code'], $journey['legs'][3]['route']['code']);
        $this->assertSame('Carbon Market Unit 3', $journey['legs'][4]['destination']['name']);
        $this->assertSame('MIXED', $journey['trust']['state']);
    }

    public function test_stale_patterns_are_not_routable(): void
    {
        RoutePattern::where('stable_id', 'pattern-17b-forward')->update([
            'verification_state' => VerificationState::STALE,
        ]);
        RoutePattern::where('stable_id', 'pattern-17b-backward')->update([
            'verification_state' => VerificationState::STALE,
        ]);

        $response = $this->journey('lahug-market', 'carbon-market-unit-3');
        $response->assertOk()->assertJsonPath('meta.no_route', null);
        $this->assertNotEmpty($response->json('data'));
        foreach ($response->json('data') as $journey) {
            foreach ($journey['legs'] as $leg) {
                $this->assertNotSame('17B', $leg['route']['code'] ?? null);
                if ($leg['type'] === 'RIDE') {
                    $this->assertNotSame('STALE', $leg['boarding_association']['state']);
                    $this->assertNotSame('STALE', $leg['alighting_association']['state']);
                }
            }
        }
    }

    public function test_invalid_and_unknown_journey_inputs_are_explained(): void
    {
        $this->getJson('/api/v1/journeys')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_INPUT');

        $samePlace = Place::where('slug', 'lahug-market')->firstOrFail();
        $this->getJson('/api/v1/journeys?from_place_id='.$samePlace->id.'&to_place_id='.$samePlace->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_INPUT');

        $this->getJson('/api/v1/journeys?from_place_id=999999&to_place_id='.$samePlace->id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'UNKNOWN_ORIGIN_PLACE');
    }

    public function test_public_journeys_do_not_include_demo_records(): void
    {
        $response = $this->journey('lahug-market', 'carbon-market-unit-3');

        foreach ($response->json('data') as $journey) {
            $this->assertFalse($journey['origin']['verification']['is_demo']);
            $this->assertFalse($journey['destination']['verification']['is_demo']);
            foreach ($journey['legs'] as $leg) {
                $this->assertNotSame(true, $leg['boarding_stop']['verification']['is_demo'] ?? null);
                $this->assertNotSame(true, $leg['alighting_stop']['verification']['is_demo'] ?? null);
            }
        }
    }

    private function journey(string $originSlug, string $destinationSlug)
    {
        $origin = Place::where('slug', $originSlug)->firstOrFail();
        $destination = Place::where('slug', $destinationSlug)->firstOrFail();

        return $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);
    }
}
