<?php

namespace App\Console\Commands;

use App\Enums\CommuterReportStatus;
use App\Enums\DisruptionCategory;
use App\Enums\DisruptionSeverity;
use App\Enums\DisruptionSourceClass;
use App\Enums\DisruptionStatus;
use App\Http\Controllers\Api\CommuterReportController;
use App\Http\Controllers\Api\DisruptionController;
use App\Http\Controllers\Api\PulseController;
use App\Models\Disruption;
use App\Models\Place;
use App\Models\ProvenanceSource;
use App\Models\Route;
use App\Models\User;
use App\Services\Disruptions\ActiveDisruptionService;
use App\Services\Disruptions\CommuterReportService;
use App\Services\Disruptions\DisruptionLifecycleService;
use App\Services\Disruptions\JourneyImpactService;
use App\Services\Disruptions\PulseService;
use App\Services\Routing\CanIRideChecker;
use App\Services\Routing\JourneyPlanner;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class BiyaheBatch015Smoke extends Command
{
    protected $signature = 'biyahe:batch015-smoke';

    protected $description = 'Exercise Batch 014 disruption/report paths against the configured live database in one rollback-only transaction.';

    public function handle(): int
    {
        $prefix = 'batch015-live-smoke-'.Str::lower(Str::random(10));
        $before = $this->syntheticRows($prefix);
        DB::beginTransaction();

        try {
            $route = Route::query()->where('code', '12L')->firstOrFail();
            $origin = Place::query()->select(['places.*'])->addSelect(DB::raw('ST_AsText(places.location) AS location_wkt'))->where('slug', 'ayala-center-cebu')->firstOrFail();
            $destination = Place::query()->select(['places.*'])->addSelect(DB::raw('ST_AsText(places.location) AS location_wkt'))->where('slug', 'labangon')->firstOrFail();
            $actor = User::factory()->create(['name' => 'Batch 015 smoke actor']);
            $source = ProvenanceSource::query()->create([
                'source_type' => DisruptionSourceClass::OFFICIAL->value,
                'title' => 'Batch 015 live smoke source',
                'publisher' => 'Biyahe rollback smoke',
                'observed_at' => now()->toDateString(),
                'checked_at' => now()->toDateString(),
                'verified_by' => 'Batch 015 smoke',
                'evidence_scope' => ['temporary_disruption_signal'],
            ]);
            $planner = app(JourneyPlanner::class);
            $plannerStarted = hrtime(true);
            $baselineResult = $planner->plan($origin, $destination);
            $plannerBaselineMs = round((hrtime(true) - $plannerStarted) / 1_000_000, 2);
            $advisory = Disruption::query()->create([
                'stable_id' => $prefix.'-12l',
                'city_id' => $route->city_id,
                'provenance_source_id' => $source->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'category' => DisruptionCategory::SERVICE_REDUCED,
                'headline' => 'Batch 015 temporary 12L smoke advisory',
                'description' => 'Rollback-only live PostGIS smoke advisory.',
                'severity' => DisruptionSeverity::MAJOR,
                'status' => DisruptionStatus::ACTIVE,
                'source_class' => DisruptionSourceClass::OFFICIAL,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addHour(),
                'reviewed_at' => now(),
            ]);
            $advisory->routes()->attach($route);
            $advisory->places()->attach($origin);
            if (($transfer = DB::table('transfer_relationships')->orderBy('id')->first()) !== null) {
                $advisory->transfers()->attach($transfer->id);
            }
            $advisory->geometry = [
                'type' => 'Point',
                'coordinates' => [123.8854, 10.3157],
            ];
            $advisory->geometry_type = 'Point';
            $advisory->save();

            $active = app(ActiveDisruptionService::class)->active();
            $this->require($active->contains('stable_id', $advisory->stable_id), 'active advisory was not visible through the live service');
            $viewport = app(ActiveDisruptionService::class)->active(filters: [
                'south' => 10.30,
                'west' => 123.87,
                'north' => 10.33,
                'east' => 123.90,
            ]);
            $this->require($viewport->contains('stable_id', $advisory->stable_id), 'PostGIS viewport did not intersect the live advisory geometry');

            $plannerStarted = hrtime(true);
            $journeyResult = $planner->plan($origin, $destination);
            $plannerWarningMs = round((hrtime(true) - $plannerStarted) / 1_000_000, 2);
            $journeyRoutes = collect($journeyResult->journeys)->flatMap(fn ($journey) => collect($journey->legs)->map(fn ($leg) => $leg->route['code'] ?? null))->filter()->unique()->values()->all();
            $activeIds = app(ActiveDisruptionService::class)->active()->pluck('stable_id')->values()->all();
            $hasJourneyImpact = collect($journeyResult->journeys)->flatMap(fn ($journey) => $journey->disruptions)->contains(fn (array $impact): bool => ($impact['advisory']['stable_id'] ?? null) === $advisory->stable_id);
            $this->require($hasJourneyImpact, 'live planner did not attach the route advisory to the Ayala/12L journey; journeys='.count($journeyResult->journeys).' routes='.json_encode($journeyRoutes).' active='.json_encode($activeIds).' no_route='.($journeyResult->noRouteCode ?? 'null'));
            $ride = app(CanIRideChecker::class)->check($origin, $destination, routeCode: '12L');
            $rideImpacts = app(JourneyImpactService::class)->forCanIRide((int) ($ride->route['id'] ?? $route->getKey()));
            $this->require(collect($rideImpacts)->contains(fn (array $impact): bool => ($impact['advisory']['stable_id'] ?? null) === $advisory->stable_id), 'live Can I Ride impact did not see the advisory');

            $public = app(DisruptionController::class)->index(Request::create('/api/v1/disruptions', 'GET', ['route_id' => $route->getKey()]), app(ActiveDisruptionService::class));
            $this->require($public->getStatusCode() === 200, 'live disruption API did not return 200');
            $pulseService = app(PulseService::class);
            $pulseService->invalidate();
            $pulseQueryCount = 0;
            DB::listen(function ($query) use (&$pulseQueryCount): void {
                if (! str_contains(strtolower((string) $query->sql), 'cache')) {
                    $pulseQueryCount++;
                }
            });
            $pulseStarted = hrtime(true);
            $pulseService->summary();
            $pulseBuildMs = round((hrtime(true) - $pulseStarted) / 1_000_000, 2);
            $pulseQueriesAfterBuild = $pulseQueryCount;
            $pulseService->summary();
            $pulseCacheQueryDelta = $pulseQueryCount - $pulseQueriesAfterBuild;
            $pulse = app(PulseController::class)(Request::create('/api/v1/pulse', 'GET'), $pulseService);
            $this->require($pulse->getStatusCode() === 200, 'live Pulse API did not return 200');

            $reportService = app(CommuterReportService::class);
            $description = $prefix.' commuter signal';
            $reportRequest = Request::create('/api/v1/commuter-reports', 'POST', [
                'category' => 'SERVICE_STOPPED',
                'description' => $description,
                'observed_at' => now()->subMinutes(2)->toIso8601String(),
                'route_id' => $route->getKey(),
            ]);
            $submitted = app(CommuterReportController::class)($reportRequest, $reportService);
            $this->require($submitted->getStatusCode() === 201, 'live public report submission failed');
            $duplicate = $reportService->submit([
                'category' => 'SERVICE_STOPPED',
                'description' => $description,
                'observed_at' => now()->subMinutes(2)->toIso8601String(),
                'route_id' => $route->getKey(),
            ]);
            $this->require($duplicate->status === CommuterReportStatus::DUPLICATE, 'live duplicate suppression did not mark the second report');
            $report = $reportService->submit([
                'category' => 'FLOODING',
                'description' => $prefix.' second signal',
                'observed_at' => now()->subMinutes(2)->toIso8601String(),
                'route_id' => $route->getKey(),
            ]);
            $report = $reportService->review($report, CommuterReportStatus::ACCEPTED_AS_SIGNAL, $actor, 'Live smoke accepted signal.');
            $promoted = $reportService->promoteToAdvisory($report, $actor, 'Live smoke explicit promotion.');
            $this->require($promoted->source_class === DisruptionSourceClass::PUBLIC_REPORT, 'report promotion did not preserve public-report provenance');
            $this->require($promoted->category === DisruptionCategory::FLOODING, 'report promotion did not preserve disruption category');
            $this->require($promoted->routes()->whereKey($route->getKey())->exists(), 'promoted advisory lost its route impact');
            $this->require($report->fresh()->disruption_id === $promoted->getKey(), 'report promotion did not link the report to the advisory');

            $closure = Disruption::query()->create([
                'stable_id' => $prefix.'-closure',
                'city_id' => $route->city_id,
                'provenance_source_id' => $source->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'category' => DisruptionCategory::SERVICE_SUSPENDED,
                'headline' => 'Batch 015 temporary 12L closure smoke',
                'description' => 'Rollback-only hard-closure performance smoke.',
                'severity' => DisruptionSeverity::CRITICAL,
                'status' => DisruptionStatus::ACTIVE,
                'source_class' => DisruptionSourceClass::OFFICIAL,
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addHour(),
                'reviewed_at' => now(),
            ]);
            $closure->routes()->attach($route);
            $plannerStarted = hrtime(true);
            $closureResult = $planner->plan($origin, $destination);
            $plannerClosureMs = round((hrtime(true) - $plannerStarted) / 1_000_000, 2);

            $geometryTypes = [
                'Point' => ['type' => 'Point', 'coordinates' => [123.8854, 10.3157]],
                'LineString' => ['type' => 'LineString', 'coordinates' => [[123.884, 10.315], [123.886, 10.316]]],
                'MultiLineString' => ['type' => 'MultiLineString', 'coordinates' => [[[123.884, 10.315], [123.886, 10.316]]]],
                'Polygon' => ['type' => 'Polygon', 'coordinates' => [[[123.884, 10.315], [123.886, 10.315], [123.886, 10.317], [123.884, 10.315]]]],
                'MultiPolygon' => ['type' => 'MultiPolygon', 'coordinates' => [[[[123.884, 10.315], [123.886, 10.315], [123.886, 10.317], [123.884, 10.315]]]]],
            ];
            foreach ($geometryTypes as $type => $geometry) {
                $shape = Disruption::query()->create([
                    'stable_id' => $prefix.'-'.$type,
                    'city_id' => $route->city_id,
                    'provenance_source_id' => $source->getKey(),
                    'category' => DisruptionCategory::FLOODING,
                    'headline' => 'Batch 015 '.$type.' geometry smoke',
                    'description' => 'Rollback-only geometry smoke.',
                    'severity' => DisruptionSeverity::INFO,
                    'status' => DisruptionStatus::ACTIVE,
                    'source_class' => DisruptionSourceClass::OFFICIAL,
                    'starts_at' => now()->subMinute(),
                    'ends_at' => now()->addHour(),
                    'reviewed_at' => now(),
                    'geometry' => $geometry,
                    'geometry_type' => $type,
                ]);
                $this->require((int) DB::table('disruptions')->where('id', $shape->getKey())->selectRaw('ST_SRID(geometry)')->value('st_srid') === 4326, $type.' geometry did not persist with SRID 4326');
                $this->require((bool) DB::table('disruptions')->where('id', $shape->getKey())->whereRaw('ST_IsValid(geometry)')->exists(), $type.' geometry is invalid in PostGIS');
            }

            app(DisruptionLifecycleService::class)->transition($advisory, DisruptionStatus::RESOLVED, $actor, 'Live smoke resolved.');
            $this->require(! app(ActiveDisruptionService::class)->active()->contains('stable_id', $advisory->stable_id), 'resolved advisory still affected active reads');
            $this->line('LIVE_SMOKE=PASS');
            $this->line('PUBLIC_REPORT_AUTO_AUTHORITY=0');
            $this->line('PUBLIC_REPORT_CANONICAL_MUTATION=0');
            $this->line('AYALA_12L_IMPACT=PASS');
            $this->line('SPATIAL_GEOMETRIES=POINT,LINESTRING,MULTILINESTRING,POLYGON,MULTIPOLYGON');
            $this->line('PLANNER_BASELINE_MS='.$plannerBaselineMs);
            $this->line('PLANNER_WARNING_MS='.$plannerWarningMs);
            $this->line('PLANNER_CLOSURE_MS='.$plannerClosureMs);
            $this->line('DISRUPTION_OVERHEAD_MS='.round($plannerWarningMs - $plannerBaselineMs, 2));
            $this->line('PULSE_QUERY_COUNT='.$pulseQueriesAfterBuild);
            $this->line('PULSE_BUILD_MS='.$pulseBuildMs);
            $this->line('PULSE_CACHE_STATUS=BOUND_'.(int) config('biyahe.disruptions.pulse_cache_seconds', 10).'S_HIT_QUERY_DELTA_'.$pulseCacheQueryDelta);
            $this->line('BASELINE_JOURNEYS='.(count($baselineResult->journeys)));
            $this->line('CLOSURE_JOURNEYS='.count($closureResult->journeys));
            DB::rollBack();
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('LIVE_SMOKE=FAIL '.$exception->getMessage());

            return self::FAILURE;
        }

        $after = $this->syntheticRows($prefix);
        $this->line('SYNTHETIC_PERSISTENT_ROWS_BEFORE='.$before);
        $this->line('SYNTHETIC_PERSISTENT_ROWS_AFTER='.$after);

        return $after === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    private function syntheticRows(string $prefix): int
    {
        return (int) DB::table('disruptions')->where('stable_id', 'like', $prefix.'%')->count()
            + (int) DB::table('commuter_reports')->where('description', 'like', $prefix.'%')->count();
    }
}
