<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\City;
use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

class StopFactory extends Factory
{
    protected $model = Stop::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => fake()->unique()->streetName().' Stop',
            'code' => fake()->unique()->bothify('STP-###'),
            'category' => 'GENERAL',
            'location' => ['lat' => fake()->latitude(9, 12), 'lng' => fake()->longitude(123, 126)],
            'is_boarding_point' => true,
            'boarding_notes' => fake()->sentence(),
            'is_active' => true,
            'verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
        ];
    }
}
