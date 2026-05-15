<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $status = fake()->randomElement(['open', 'triaged', 'resolved', 'dismissed']);

        return [
            'reporter_id' => User::factory(),
            'handled_by' => $status === 'open' ? null : User::factory(),
            'entity_type' => 'event',
            'entity_id' => Event::factory(),
            'category' => fake()->randomElement([
                'wrong_info',
                'cancelled_not_updated',
                'fake_speaker',
                'inappropriate_content',
                'donation_scam',
                'other',
            ]),
            'description' => fake()->optional()->paragraph(),
            'status' => $status,
            'resolution_note' => in_array($status, ['resolved', 'dismissed'], true)
                ? fake()->optional()->sentence()
                : null,
        ];
    }
}
