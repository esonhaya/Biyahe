<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RouteResource;
use App\Models\Route;
use App\Services\Disruptions\ActiveDisruptionService;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteController
{
    public function index(Request $request)
    {
        $query = Route::query()->with([
            'provenanceSource',
            'city',
            'operator',
            'vehicleType',
            'patterns' => function ($builder): void {
                $builder->with('provenanceSource');
                if (config('database.default') === 'pgsql') {
                    $builder->addSelect(['route_patterns.*', DB::raw('ST_AsText(route_patterns.geometry) AS geometry_wkt')]);
                }
            },
        ])->orderBy('routes.code');
        Trust::apply($query, $request, 'routes');
        if (! $request->boolean('include_inactive')) {
            $query->where('routes.is_active', true);
        }
        if ($request->filled('city')) {
            $city = (string) $request->query('city');
            $query->whereHas('city', function (Builder $builder) use ($city): void {
                $builder->where('slug', $city);
                if (ctype_digit($city)) {
                    $builder->orWhereKey((int) $city);
                }
            });
        }
        if ($request->filled('vehicle_type')) {
            $query->whereHas('vehicleType', fn (Builder $builder) => $builder->where('code', strtoupper((string) $request->query('vehicle_type'))));
        }
        if ($request->filled('q')) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('LOWER(routes.code) LIKE ?', [$term])->orWhereRaw('LOWER(routes.name) LIKE ?', [$term]);
            });
        }

        return RouteResource::collection($query->paginate(min(max((int) $request->query('per_page', 20), 1), 100))->withQueryString());
    }

    public function show(Request $request, Route $route): RouteResource
    {
        $query = Route::query();
        Trust::apply($query, $request, 'routes');
        $route = $query->findOrFail($route->id);
        $route->load([
            'city',
            'operator',
            'vehicleType',
            'provenanceSource',
            'patterns' => function ($builder): void {
                if (config('database.default') === 'pgsql') {
                    $builder->addSelect(['route_patterns.*', DB::raw('ST_AsText(route_patterns.geometry) AS geometry_wkt')]);
                }
                $builder->with(['patternStops' => function ($patternStops): void {
                    $patternStops->with([
                        'provenanceSource',
                        'serviceProvenanceSource',
                        'sequenceProvenanceSource',
                        'boardingProvenanceSource',
                        'alightingProvenanceSource',
                        'stop.provenanceSource',
                        'stop.boardingProvenanceSource',
                        'stop' => function ($stop): void {
                            if (config('database.default') === 'pgsql') {
                                $stop->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')]);
                            }
                        }]);
                }]);
            },
        ]);

        return new RouteResource($route);
    }

    /**
     * Return a bounded route directory around an explicitly selected point.
     * The query is PostGIS-backed in production and returns only nearby
     * mapped boarding candidates; it does not imply that a vehicle is
     * present or that the exact curb is verified.
     */
    public function nearby(Request $request, ActiveDisruptionService $disruptions): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:25', 'max:5000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        if (config('database.default') !== 'pgsql') {
            return response()->json([
                'message' => 'Nearby route discovery requires the PostGIS runtime.',
                'error' => ['code' => 'POSTGIS_REQUIRED', 'message' => 'Use the real PostgreSQL/PostGIS environment for bounded nearby queries.'],
            ], 422);
        }

        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lng'];
        $radius = (float) ($validated['radius'] ?? 1000);
        $limit = (int) ($validated['limit'] ?? 20);
        $point = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';

        $candidateRows = DB::table('route_pattern_stops AS rps')
            ->join('route_patterns AS rp', 'rp.id', '=', 'rps.route_pattern_id')
            ->join('routes AS r', 'r.id', '=', 'rp.route_id')
            ->join('stops AS s', 's.id', '=', 'rps.stop_id')
            ->join('vehicle_types AS vt', 'vt.id', '=', 'r.vehicle_type_id')
            ->where('rps.boarding_allowed', true)
            ->whereIn('rps.verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->whereIn('rps.service_verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->whereIn('rps.sequence_verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->whereIn('rps.boarding_verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->whereIn('rps.alighting_verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->where('rp.is_active', true)
            ->where('rp.is_demo', false)
            ->whereIn('rp.verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->where('r.is_active', true)
            ->where('r.is_demo', false)
            ->whereIn('r.verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->where('s.is_active', true)
            ->where('s.is_demo', false)
            ->whereIn('s.verification_state', ['VERIFIED', 'UNVERIFIED'])
            ->whereNotNull('s.location')
            ->whereRaw("ST_DWithin(s.location, {$point}, ?)", [$longitude, $latitude, $radius])
            ->select([
                'r.id AS route_id',
                'r.code AS route_code',
                'r.name AS route_name',
                'r.verification_state AS route_verification_state',
                'vt.code AS vehicle_type_code',
                'vt.name AS vehicle_type_name',
                'rp.id AS pattern_id',
                'rp.name AS pattern_name',
                'rp.direction AS pattern_direction',
                's.id AS stop_id',
                's.name AS stop_name',
                's.code AS stop_code',
                's.boarding_precision',
                'rps.boarding_verification_state',
            ])
            ->selectRaw("ST_Distance(s.location, {$point}) AS distance_meters", [$longitude, $latitude])
            ->orderBy('distance_meters')
            ->orderBy('r.id')
            ->orderBy('rp.id')
            ->limit(min(250, $limit * 8))
            ->get();

        $activeAdvisories = $disruptions->active($disruptions->now())
            ->flatMap(function ($advisory): array {
                return $advisory->routes->mapWithKeys(fn ($route): array => [(int) $route->getKey() => [
                    'stable_id' => $advisory->stable_id,
                    'headline' => $advisory->headline,
                    'severity' => $advisory->severity?->value ?? $advisory->severity,
                ]])->all();
            });

        $routes = $candidateRows
            ->groupBy('route_id')
            ->map(function ($rows, $routeId) use ($activeAdvisories): array {
                $first = $rows->first();
                $candidates = $rows
                    ->unique(fn ($row): string => $row->pattern_id.'-'.$row->stop_id)
                    ->take(5)
                    ->map(fn ($row): array => [
                        'pattern' => [
                            'id' => (int) $row->pattern_id,
                            'name' => $row->pattern_name,
                            'direction' => $row->pattern_direction,
                        ],
                        'stop' => [
                            'id' => (int) $row->stop_id,
                            'name' => $row->stop_name,
                            'code' => $row->stop_code,
                            'boarding_precision' => $row->boarding_precision,
                            'boarding_verification_state' => $row->boarding_precision === 'UNKNOWN'
                                ? 'UNVERIFIED'
                                : $row->boarding_verification_state,
                        ],
                        'distance_meters' => round((float) $row->distance_meters, 2),
                    ])->values()->all();

                return [
                    'id' => (int) $routeId,
                    'code' => $first->route_code,
                    'name' => $first->route_name,
                    'vehicle_type' => [
                        'code' => $first->vehicle_type_code,
                        'name' => $first->vehicle_type_name,
                    ],
                    'verification' => [
                        'state' => $first->route_verification_state,
                        'trusted' => $first->route_verification_state === 'VERIFIED',
                    ],
                    'nearest_distance_meters' => round((float) $rows->min('distance_meters'), 2),
                    'boarding_candidates' => $candidates,
                    'active_advisories' => $activeAdvisories->get((int) $routeId, []),
                    'nearby_note' => 'Nearby means close to a mapped boarding candidate; it does not mean a vehicle is present or that the exact curb is verified.',
                ];
            })
            ->sortBy('nearest_distance_meters')
            ->take($limit)
            ->values()
            ->all();

        return response()->json([
            'data' => $routes,
            'meta' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_meters' => $radius,
                'count' => count($routes),
                'location_is_ephemeral' => true,
                'vehicle_presence' => 'NOT_DETERMINED',
            ],
        ]);
    }
}
