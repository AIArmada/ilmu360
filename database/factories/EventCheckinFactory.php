<?php

declare(strict_types=1);

namespace Database\Factories;

use AIArmada\Events\Database\Factories\EventAttendanceFactory;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\User;

/**
 * @extends Factory<EventCheckin>
 */
class EventCheckinFactory extends EventAttendanceFactory
{
    protected $model = EventCheckin::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'method' => fake()->randomElement(['self_reported', 'registered_self_checkin', 'organizer_verified']),
            'checked_in_at' => now()->subMinutes(fake()->numberBetween(1, 120)),
            'lat' => fake()->optional()->latitude(1.2, 6.8),
            'lng' => fake()->optional()->longitude(99.6, 119.3),
            'accuracy_m' => fake()->optional()->randomFloat(2, 3, 80),
            'attendance_type' => 'check_in',
        ];
    }
}
