<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardEnrollmentStatus;
use App\Models\RewardEnrollment;
use App\Models\RewardProgram;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardEnrollment>
 */
class RewardEnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'program_id' => RewardProgram::factory(),
            'member_type' => 'user',
            'member_id' => (string) Str::uuid(),
            'status' => RewardEnrollmentStatus::Active,
            'current_acceptance_id' => null,
            'enrolled_at' => now(),
            'left_at' => null,
        ];
    }

    public function left(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RewardEnrollmentStatus::Left,
            'left_at' => now(),
        ]);
    }
}
