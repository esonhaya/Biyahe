<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\City;
use App\Models\Place;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlaceFactory extends Factory
{
    protected $model = Place::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'city_id' => City::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'category' => 'LANDMARK',
            'description' => fake()->sentence(),
            'location' => ['lat' => fake()->latitude(9, 12), 'lng' => fake()->longitude(123, 126)],
            'is_active' => true,
            'verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
        ];
    }
}
