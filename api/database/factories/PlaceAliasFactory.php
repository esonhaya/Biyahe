<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\PlaceAlias;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlaceAliasFactory extends Factory
{
    protected $model = PlaceAlias::class;

    public function definition(): array
    {
        $alias = fake()->unique()->words(2, true);

        return [
            'place_id' => Place::factory(),
            'alias' => $alias,
            'normalized_alias' => Str::lower($alias),
            'is_active' => true,
        ];
    }
}
