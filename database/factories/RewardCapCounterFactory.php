<?php

namespace Database\Factories;

use App\Models\RewardCapCounter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardCapCounter>
 */
class RewardCapCounterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cap_key' => 'pt:'.Str::lower(Str::random(8)),
            'window_key' => 'total',
            'family_key' => 'family-'.Str::lower(Str::random(6)),
            'scope' => 'program_total',
            'limit_value' => 100,
            'consumed' => 0,
        ];
    }
}
