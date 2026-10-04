<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\StopResource;
use App\Models\Stop;
use App\Support\Geo;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StopController
{
    public function index(Request $request)
    {
        $query = $this->baseQuery()->orderBy('stops.name');
        Trust::apply($query, $request, 'stops');
        if (! $request->boolean('include_inactive')) {
            $query->where('stops.is_active', true);
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

        return StopResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Request $request, Stop $stop): StopResource
    {
        $query = $this->baseQuery();
        Trust::apply($query, $request, 'stops');
        $stop = $query->findOrFail($stop->id);

        return new StopResource($stop);
    }

    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:10000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lng'];
        $radius = (float) ($validated['radius'] ?? 1000);
        $limit = (int) ($validated['limit'] ?? 50);

        if (config('database.default') === 'pgsql') {
            $point = 'ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography';
            $query = $this->baseQuery();
            Trust::apply($query, $request, 'stops');
            $query = $query
                ->where('stops.is_active', true)
                ->whereRaw("ST_DWithin(stops.location, {$point}, ?)", [$longitude, $latitude, $radius])
                ->selectRaw("ST_Distance(stops.location, {$point}) AS distance_meters", [$longitude, $latitude])
                ->orderBy('distance_meters')
                ->orderBy('stops.id')
                ->limit($limit)
                ->get();
        } else {
            $query = $this->baseQuery();
            Trust::apply($query, $request, 'stops');
            $query = $query->where('stops.is_active', true)->get()
                ->map(function (Stop $stop) use ($latitude, $longitude): Stop {
                    $point = $stop->location_point;
                    $stop->distance_meters = $point === null
                        ? INF
                        : Geo::distanceMeters($latitude, $longitude, $point['lat'], $point['lng']);

                    return $stop;
                })
                ->filter(fn (Stop $stop): bool => $stop->distance_meters <= $radius)
                ->sortBy('distance_meters')
                ->take($limit)
                ->values();
        }

        return response()->json([
            'data' => StopResource::collection($query)->resolve($request),
            'meta' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_meters' => $radius,
                'distance_unit' => 'meters',
                'count' => $query instanceof Collection ? $query->count() : $query->count(),
            ],
        ]);
    }

    private function baseQuery(): Builder
    {
        $query = Stop::query()->with([
            'city',
            'provenanceSource',
            'nearbyPlace' => function ($place): void {
                if (config('database.default') === 'pgsql') {
                    $place->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]);
                }
            },
        ]);
        if (config('database.default') === 'pgsql') {
            $query->addSelect(['stops.*', DB::raw('ST_AsText(stops.location) AS location_wkt')]);
        }

        return $query;
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }
}
