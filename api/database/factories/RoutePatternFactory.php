<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\Route;
use App\Models\RoutePattern;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoutePatternFactory extends Factory
{
    protected $model = RoutePattern::class;

    public function definition(): array
    {
        return [
            'route_id' => Route::factory(),
            'name' => fake()->unique()->words(2, true),
            'direction' => 'OUTBOUND',
            'service_notes' => fake()->sentence(),
            'geometry' => 'SRID=4326;MULTILINESTRING((123.885 10.315,123.895 10.320))',
            'is_active' => true,
            'verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
        ];
    }
}
