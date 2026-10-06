<?php

namespace Database\Factories;

use App\Enums\FieldLocationMethod;
use App\Models\SurveyTracePoint;
use App\Models\TransportSurveySession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SurveyTracePoint>
 */
class SurveyTracePointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stable_id' => 'test-trace-'.Str::uuid(),
            'survey_session_id' => TransportSurveySession::factory(),
            'sequence' => 1,
            'location' => ['lat' => 10.3157, 'lng' => 123.8854],
            'location_method' => FieldLocationMethod::DEVICE,
            'import_hash' => hash('sha256', Str::uuid()->toString()),
        ];
    }
}
