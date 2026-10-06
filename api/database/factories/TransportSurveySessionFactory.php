<?php

namespace Database\Factories;

use App\Enums\SurveySessionStatus;
use App\Models\City;
use App\Models\TransportSurveySession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TransportSurveySession>
 */
class TransportSurveySessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stable_id' => 'test-survey-'.Str::uuid(),
            'title' => fake()->sentence(4),
            'purpose' => fake()->sentence(),
            'city_id' => City::factory(),
            'observer_reference' => 'synthetic-test-observer',
            'status' => SurveySessionStatus::PLANNED,
            'is_synthetic' => true,
        ];
    }
}
