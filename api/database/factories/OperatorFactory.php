<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\Operator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OperatorFactory extends Factory
{
    protected $model = Operator::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'is_active' => true,
            'verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
        ];
    }
}
