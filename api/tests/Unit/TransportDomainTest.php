<?php

namespace Tests\Unit;

use App\Enums\VerificationState;
use App\Models\Fare;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransportDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_routes_have_directional_patterns_and_contiguous_order(): void
    {
        $this->seed();

        $route = Route::where('code', 'DEMO-04L')->with('patterns.patternStops.stop')->firstOrFail();
        $this->assertCount(2, $route->patterns);
        $this->assertNotSame($route->patterns[0]->id, $route->patterns[1]->id);

        foreach ($route->patterns as $pattern) {
            $this->assertSame([1, 2, 3], $pattern->patternStops->pluck('sequence')->all());
            $this->assertTrue($pattern->patternStops->every(fn (RoutePatternStop $stop): bool => $stop->boarding_allowed && $stop->alighting_allowed));
        }
    }

    public function test_shared_stops_and_fare_foundation_are_relational(): void
    {
        $this->seed();

        $sharedStop = Stop::where('code', 'DEMO-D')->firstOrFail();
        $this->assertGreaterThanOrEqual(2, RoutePatternStop::where('stop_id', $sharedStop->id)->count());
        $this->assertSame(VerificationState::DRAFT, RoutePattern::firstOrFail()->verification_state);
        $this->assertSame('PHP', Fare::firstOrFail()->currency);
    }
}
