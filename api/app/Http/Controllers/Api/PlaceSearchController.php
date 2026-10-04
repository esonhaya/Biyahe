<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PlaceResource;
use App\Models\Place;
use App\Support\Trust;
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
            });
        Trust::apply($query, $request, 'places');
        if (! empty($validated['city'])) {
            $city = (string) $validated['city'];
            $query->whereHas('city', function (Builder $builder) use ($city): void {
                $builder->where('slug', $city);
                if (ctype_digit($city)) {
                    $builder->orWhereKey((int) $city);
                }
            });
        }

        $query
            ->orderBy('places.name')
            ->limit($validated['limit'] ?? 20);

        return response()->json(['data' => PlaceResource::collection($query->get())->resolve($request)]);
    }
}
