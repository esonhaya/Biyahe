<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RoutePatternResource;
use App\Models\RoutePattern;
use Illuminate\Support\Facades\DB;

class RoutePatternController
{
    public function show(RoutePattern $routePattern): RoutePatternResource
    {
        $query = RoutePattern::query()
            ->with(['route.city', 'provenanceSource'])
            ->with(['patternStops' => function ($builder): void {
                $builder->with(['stop' => function ($stop): void {
                    if (config('database.default') === 'pgsql') {
                        $stop->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')]);
                    }
                }]);
            }]);

        if (config('database.default') === 'pgsql') {
            $query->addSelect(['route_patterns.*', DB::raw('ST_AsText(route_patterns.geometry) AS geometry_wkt')]);
        }

        return new RoutePatternResource($query->findOrFail($routePattern->id));
    }
}
