<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => fake()->boolean(70) ? User::factory() : null,
            'name' => fake()->name(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'status' => fake()->randomElement(['registered', 'cancelled', 'attended', 'no_show']),
            'checkin_token' => fake()->boolean(10) ? Str::random(40) : null,
        ];
    }
}
