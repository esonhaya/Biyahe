<?php

namespace Tests\Feature;

use App\Models\Fare;
use App\Models\Place;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\TransferRelationship;
use App\Services\Data\DatasetValidator;
use App\Services\Routing\JourneyPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Batch004NetworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_expansion_has_directional_routes_and_granular_association_trust(): void
    {
        $this->assertSame(6, Route::whereIn('code', ['03B', '04L', '06B', '06C', '06H', '13C'])->count());
        $this->assertSame(12, RoutePattern::whereHas('route', fn ($query) => $query->whereIn('code', ['03B', '04L', '06B', '06C', '06H', '13C']))->count());
        $this->assertSame(60, RoutePatternStop::whereHas('routePattern.route', fn ($query) => $query->whereIn('code', ['03B', '04L', '06B', '06C', '06H', '13C']))->count());
        $this->assertSame(60, RoutePatternStop::where('verification_state', 'UNVERIFIED')->whereHas('routePattern.route', fn ($query) => $query->whereIn('code', ['03B', '04L', '06B', '06C', '06H', '13C']))->count());
        $this->assertSame(4, Fare::where('verification_state', 'STALE')->count());
        $this->assertSame(12, Fare::where('verification_state', 'RETIRED')->count());
        $this->assertSame(2, TransferRelationship::whereNotNull('practical_note')->count());
    }

    public function test_expansion_dataset_validates_and_search_prioritizes_canonical_places(): void
    {
        $result = app(DatasetValidator::class)->validate(database_path('data/cebu-network-expansion-2026-10-05.json'));

        $this->assertTrue($result->isValid(), json_encode($result->errors()));

        $this->getJson('/api/v1/places/search?q=itpark')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cebu IT Park');

        $this->getJson('/api/v1/places/search?q=SM%20City')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'SM City Cebu');
    }

    public function test_transfer_candidates_remain_review_required(): void
    {
        $this->artisan('biyahe:transfers:candidates', [
            '--city' => 'cebu-city',
            '--limit' => 40,
        ])
            ->expectsOutputToContain('REVIEW_REQUIRED')
            ->assertExitCode(0);
    }

    public function test_expanded_journey_keeps_fare_unknown_and_exposes_boarding_evidence(): void
    {
        $origin = Place::where('slug', 'lahug-market')->firstOrFail();
        $destination = Place::where('slug', 'carbon-market-unit-3')->firstOrFail();

        $response = $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id);
        $response->assertOk();

        $journey = collect($response->json('data'))->firstWhere('transfer_count', 0);
        $this->assertNotNull($journey);
        $ride = collect($journey['legs'])->firstWhere('type', 'RIDE');
        $this->assertNotNull($ride['boarding_instruction']);
        $this->assertSame('UNAVAILABLE', $journey['fare']['status']);
        $this->assertNull($journey['fare']['amount']);
        $this->assertSame($ride['alighting_sequence'], $ride['boarding_sequence'] + $ride['stop_count']);
    }

    public function test_directional_curated_transfer_can_be_found_without_promoting_its_trust(): void
    {
        config(['biyahe.routing.max_results' => 100]);
        $origin = $this->plannerPlace('ayala-center-cebu');
        $destination = $this->plannerPlace('carbon-market-unit-3');
        $result = app(JourneyPlanner::class)->plan($origin, $destination);

        $journey = collect($result->journeys)->first(function ($candidate): bool {
            $routeCodes = collect($candidate->legs)
                ->filter(fn ($leg): bool => $leg->route !== null)
                ->map(fn ($leg): string => $leg->route['code'])
                ->all();

            return $candidate->transferCount === 1
                && in_array('12L', $routeCodes, true)
                && in_array('17B', $routeCodes, true);
        });

        $this->assertNotNull($journey);
        $this->assertSame('MIXED', $journey->trustState->value);
        $this->assertNotSame('VERIFIED', $journey->legs[2]->trust->value);
    }

    public function test_tight_access_radius_reports_missing_supported_boarding_point(): void
    {
        $origin = Place::where('slug', 'cebu-it-park')->firstOrFail();
        $destination = Place::where('slug', 'carbon-market-unit-3')->firstOrFail();

        $this->getJson('/api/v1/journeys?from_place_id='.$origin->id.'&to_place_id='.$destination->id.'&access_radius=50')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.no_route.code', 'NO_NEARBY_BOARDING');
    }

    private function plannerPlace(string $slug): Place
    {
        $query = Place::query()->where('slug', $slug);
        if (config('database.default') === 'pgsql') {
            $query->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]);
        }

        return $query->firstOrFail();
    }
}
