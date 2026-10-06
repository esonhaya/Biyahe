<?php

namespace Database\Factories;

use App\Enums\EvidenceClaimType;
use App\Enums\FieldClaimComparison;
use App\Enums\FieldClaimStatus;
use App\Enums\FieldClaimTargetType;
use App\Models\FieldEvidenceClaim;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FieldEvidenceClaim>
 */
class FieldEvidenceClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stable_id' => 'test-field-claim-'.Str::uuid(),
            'claim_type' => EvidenceClaimType::ROUTE_IDENTITY,
            'target_type' => FieldClaimTargetType::ROUTE,
            'target_id' => 1,
            'target_stable_id' => 'synthetic-route',
            'comparison' => FieldClaimComparison::INSUFFICIENT_TO_DETERMINE,
            'claim_text' => 'Synthetic test claim.',
            'review_status' => FieldClaimStatus::PROPOSED,
        ];
    }
}
