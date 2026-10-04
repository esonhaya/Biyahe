<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CityResource;
use App\Models\City;
use App\Support\Trust;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CityController
{
    public function index(Request $request)
    {
        $query = City::query()->where('is_active', true)->orderBy('name');
        Trust::apply($query, $request, 'cities');

        if ($request->filled('q')) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$term]);
        }

        return CityResource::collection($query->paginate(min(max((int) $request->query('per_page', 20), 1), 100)));
    }

    public function show(Request $request, string $city): CityResource
    {
        $query = City::query();
        Trust::apply($query, $request, 'cities');
        $query->where(function (Builder $builder) use ($city): void {
            $builder->where('slug', $city);
            if (ctype_digit($city)) {
                $builder->orWhereKey((int) $city);
            }
        });

        return new CityResource($query->firstOrFail());
    }
}
