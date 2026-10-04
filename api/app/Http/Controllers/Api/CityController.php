<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CityResource;
use App\Models\City;
use Illuminate\Http\Request;

class CityController
{
    public function index(Request $request)
    {
        $query = City::query()->where('is_active', true)->orderBy('name');

        if ($request->filled('q')) {
            $term = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->whereRaw('LOWER(name) LIKE ?', [$term]);
        }

        return CityResource::collection($query->paginate(min((int) $request->query('per_page', 20), 100)));
    }

    public function show(City $city): CityResource
    {
        return new CityResource($city);
    }
}
