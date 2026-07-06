<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModerationReview>
 */
class ModerationReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actionable_type' => Event::class,
            'actionable_id' => Event::factory(),
            'actioned_by_type' => User::class,
            'actioned_by_id' => User::factory(),
            'type' => fake()->randomElement(['approved', 'rejected', 'changes_requested']),
            'notes' => fake()->optional()->sentence(),
            'reason' => fake()->randomElement([
                'donation_changed',
                'time_changed',
                'venue_changed',
                'speaker_changed',
                'details_incomplete',
            ]),
        ];
    }
}
