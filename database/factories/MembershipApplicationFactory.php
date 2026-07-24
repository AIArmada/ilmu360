<?php

namespace Database\Factories;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Enums\MemberSubjectType;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipApplication>
 */
class MembershipApplicationFactory extends Factory
{
    protected $model = MembershipApplication::class;

    public function definition(): array
    {
        return [
            'subject_type' => MemberSubjectType::Institution,
            'subject_id' => Institution::factory(),
            'applicant_id' => User::factory(),
            'reviewer_id' => null,
            'status' => ApplicationStatus::Pending,
            'granted_role' => null,
            'justification' => fake()->paragraph(),
            'reviewer_note' => null,
            'reviewed_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function person(): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_type' => MemberSubjectType::Person,
            'subject_id' => Person::factory(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ApplicationStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ApplicationStatus::Rejected,
            'reviewed_at' => now(),
        ]);
    }
}
