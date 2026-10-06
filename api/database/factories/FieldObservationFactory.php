<?php

namespace Database\Factories;

use App\Enums\FieldLocationMethod;
use App\Enums\FieldObservationStatus;
use App\Enums\FieldObservationType;
use App\Models\FieldObservation;
use App\Models\TransportSurveySession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FieldObservation>
 */
class FieldObservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stable_id' => 'test-observation-'.Str::uuid(),
            'survey_session_id' => TransportSurveySession::factory(),
            'sequence' => 1,
            'observation_type' => FieldObservationType::OTHER,
            'observed_at' => now(),
            'location_method' => FieldLocationMethod::UNKNOWN,
            'observed_fact' => ['description' => 'Synthetic test observation.'],
            'review_status' => FieldObservationStatus::SUBMITTED,
            'import_hash' => hash('sha256', Str::uuid()->toString()),
        ];
    }
}
