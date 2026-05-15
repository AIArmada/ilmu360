<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventSettings>
 */
class EventSettingsFactory extends Factory
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
            'registration_required' => true,
            'capacity' => fake()->numberBetween(30, 300),
            'registration_opens_at' => now()->addDays(fake()->numberBetween(1, 14)),
            'registration_closes_at' => now()->addDays(fake()->numberBetween(15, 30)),
            'requires_approval' => fake()->boolean(20),
            'allow_waitlist' => fake()->boolean(40),
            'max_per_user' => fake()->optional()->randomElement([1, 2, 4, 5]),
        ];
    }
}
