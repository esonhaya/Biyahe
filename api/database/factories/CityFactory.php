<?php

namespace Database\Factories;

use App\Enums\VerificationState;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'country_code' => 'PH',
            'is_active' => true,
            'verification_state' => VerificationState::DRAFT,
            'is_demo' => false,
        ];
    }
}
