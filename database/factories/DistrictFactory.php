<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => 132, // Malaysia default in World package usually
            'state_id' => fake()->numberBetween(1, 16),
            'country_code' => 'MY',
            'name' => fake()->unique()->city(),
        ];
    }
}
