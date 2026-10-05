<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\RoutePattern;
use App\Models\RoutePatternStop;
use App\Models\Stop;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoutePatternStopFactory extends Factory
{
    protected $model = RoutePatternStop::class;

    public function definition(): array
    {
        return [
            'route_pattern_id' => RoutePattern::factory(),
            'stop_id' => Stop::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 99),
            'boarding_allowed' => true,
            'alighting_allowed' => true,
            'verification_state' => VerificationState::UNVERIFIED,
            'instruction' => fake()->sentence(),
            'service_metadata' => ['fixture' => false],
        ];
    }
}
