<?php

namespace Database\Factories;

use App\Models\RewardEnrollment;
use App\Models\RewardEnrollmentAcceptance;
use App\Models\RewardEnrollmentPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RewardEnrollmentPeriod>
 */
class RewardEnrollmentPeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enrollment_id' => RewardEnrollment::factory(),
            'seq' => 1,
            'joined_at' => now(),
            'left_at' => null,
            'join_acceptance_id' => RewardEnrollmentAcceptance::factory(),
            'leave_reason' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'left_at' => now(),
            'leave_reason' => 'member-left',
        ]);
    }
}
