<?php

namespace Tests\Unit;

use App\Enums\VerificationState;
use App\Models\Fare;
use App\Models\Route;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
        $this->assertSame(
            VerificationState::DRAFT,
            RoutePattern::whereHas('route', fn ($query) => $query->where('code', 'DEMO-04L'))->firstOrFail()->verification_state,
        );
        $this->assertSame('PHP', Fare::firstOrFail()->currency);
    }

    public function test_geographic_values_keep_latitude_longitude_and_geojson_order(): void
    {
        $this->assertSame('SRID=4326;POINT(123.8854 10.3157)', Geo::pointWkt(10.3157, 123.8854));
        $this->assertSame(
            ['lat' => 10.3157, 'lng' => 123.8854],
            Geo::parsePoint('SRID=4326;POINT(123.8854 10.3157)'),
        );
        $this->assertSame(
            'SRID=4326;MULTILINESTRING((123.8854 10.3157,123.893 10.3074))',
            Geo::multiLineStringWkt([
                'type' => 'MultiLineString',
                'coordinates' => [[[123.8854, 10.3157], [123.893, 10.3074]]],
            ]),
        );

        $this->expectException(InvalidArgumentException::class);
        Geo::pointWkt(123.8854, 10.3157);
    }
}
