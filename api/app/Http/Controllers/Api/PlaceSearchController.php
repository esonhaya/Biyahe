<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PlaceResource;
use App\Models\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaceSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $term = '%'.mb_strtolower($validated['q']).'%';

        $query = Place::query()
            ->with(['city', 'aliases', 'provenanceSource'])
            ->where('places.is_active', true)
            ->when(config('database.default') === 'pgsql', fn (Builder $builder) => $builder->addSelect(['places.*', DB::raw('ST_AsText(places.location) AS location_wkt')]))
            ->where(function (Builder $builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(places.name) LIKE ?', [$term])
                    ->orWhereHas('aliases', fn (Builder $alias) => $alias->where('is_active', true)->whereRaw('LOWER(alias) LIKE ?', [$term]));
            })
            ->when(isset($validated['city']), fn (Builder $builder) => $builder->whereHas('city', fn (Builder $city) => $city->where('slug', $validated['city'])->orWhereKey($validated['city'])))
            ->orderBy('places.name')
            ->limit($validated['limit'] ?? 20)
            ->get();

        return response()->json(['data' => PlaceResource::collection($query)->resolve($request)]);
    }
}
