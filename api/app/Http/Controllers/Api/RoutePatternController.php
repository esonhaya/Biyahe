<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\RoutePatternResource;
use App\Models\RoutePattern;
use App\Support\Trust;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoutePatternController
{
    public function show(Request $request, RoutePattern $routePattern): RoutePatternResource
    {
        $query = RoutePattern::query()
            ->with(['route.city', 'provenanceSource'])
            ->with(['patternStops' => function ($builder): void {
                $builder->with([
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

        if (config('database.default') === 'pgsql') {
            $query->addSelect(['route_patterns.*', DB::raw('ST_AsText(route_patterns.geometry) AS geometry_wkt')]);
        }

        Trust::apply($query, $request, 'route_patterns');

        return new RoutePatternResource($query->findOrFail($routePattern->id));
    }
}
