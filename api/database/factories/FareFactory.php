<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\Fare;
use App\Models\Route;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

class FareFactory extends Factory
{
    protected $model = Fare::class;

    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'vehicle_type_id' => VehicleType::factory(),
            'fare_class' => 'REGULAR',
            'amount' => fake()->randomFloat(2, 1, 100),
            'currency' => 'PHP',
            'effective_from' => now()->toDateString(),
            'verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
        ];
    }
}
