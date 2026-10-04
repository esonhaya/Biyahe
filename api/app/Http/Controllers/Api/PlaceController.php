<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PlaceResource;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaceController
{
    public function index(Request $request)
    {
        $query = $this->baseQuery()->orderBy('places.name');
        $this->applyFilters($query, $request);

        return PlaceResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    public function show(Place $place): PlaceResource
    {
        $place = $this->withLocation(Place::query())
            ->with(['city', 'aliases', 'provenanceSource'])
            ->findOrFail($place->id);

        return new PlaceResource($place);
    }

    private function baseQuery(): Builder
    {
        return $this->withLocation(Place::query())
            ->with(['city', 'provenanceSource']);
    }

    private function withLocation(Builder $query): Builder
    {
        if (config('database.default') === 'pgsql') {
            $query->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]);
        }

        return $query;
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if (! $request->boolean('include_inactive')) {
            $query->where('places.is_active', true);
        }

        if ($request->filled('city')) {
            $city = (string) $request->query('city');
            $query->whereHas('city', fn (Builder $builder) => $builder->where('slug', $city)->orWhereKey($city));
        }

        if ($request->filled('category')) {
            $query->where('places.category', strtoupper((string) $request->query('category')));
        }

        if ($request->filled('q')) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(places.name) LIKE ?', [$term])
                    ->orWhereHas('aliases', fn (Builder $alias) => $alias->where('is_active', true)->whereRaw('LOWER(alias) LIKE ?', [$term]));
            });
        }
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 20), 1), 100);
    }
}
