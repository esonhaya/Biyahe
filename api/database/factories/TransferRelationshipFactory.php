<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\City;
use App\Models\Stop;
use App\Models\TransferRelationship;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransferRelationshipFactory extends Factory
{
    protected $model = TransferRelationship::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'from_stop_id' => Stop::factory(),
            'to_stop_id' => Stop::factory(),
            'stable_id' => fake()->unique()->slug(3),
            'relationship_type' => 'CURATED_WALK_ESTIMATE',
            'distance_meters' => fake()->randomFloat(2, 1, 600),
            'is_active' => true,
            'verification_state' => VerificationState::UNVERIFIED,
            'is_demo' => false,
        ];
    }
}
