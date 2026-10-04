<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\City;
use App\Models\Route;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RouteFactory extends Factory
{
    protected $model = Route::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'city_id' => City::factory(),
            'vehicle_type_id' => VehicleType::factory(),
            'code' => fake()->unique()->bothify('R##?'),
            'name' => $name,
            'description' => fake()->sentence(),
            'is_active' => true,
            'verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
            'notes' => Str::ucfirst(fake()->sentence()),
        ];
    }
}
