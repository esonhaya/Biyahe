<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RouteResource;
use App\Models\Route;
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
        if (! $request->boolean('include_inactive')) {
            $query->where('routes.is_active', true);
        }
        if ($request->filled('city')) {
            $city = (string) $request->query('city');
            $query->whereHas('city', fn (Builder $builder) => $builder->where('slug', $city)->orWhereKey($city));
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

    public function show(Route $route): RouteResource
    {
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
