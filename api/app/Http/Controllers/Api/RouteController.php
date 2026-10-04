<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RouteResource;
use App\Models\Route;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteController
{
    public function index(Request $request)
    {
        $query = Route::query()->with([
            'city',
            'operator',
            'vehicleType',
            'patterns' => function ($builder): void {
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
                    $patternStops->with(['stop' => function ($stop): void {
                        if (config('database.default') === 'pgsql') {
                            $stop->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')]);
                        }
                    }]);
                }]);
            },
        ]);

        return new RouteResource($route);
    }
}
