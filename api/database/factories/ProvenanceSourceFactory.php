<?php

namespace Database\Factories;

use App\Models\ProvenanceSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProvenanceSourceFactory extends Factory
{
    protected $model = ProvenanceSource::class;

    public function definition(): array
    {
        return [
            'source_type' => 'FIELD_VERIFICATION',
            'title' => fake()->sentence(3),
            'publisher' => fake()->company(),
            'url' => fake()->url(),
            'accessed_at' => fake()->date(),
            'notes' => fake()->sentence(),
        ];
    }
}
