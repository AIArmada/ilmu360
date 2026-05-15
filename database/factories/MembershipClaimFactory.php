<?php

namespace Database\Factories;

use App\Enums\MembershipClaimStatus;
use App\Enums\MemberSubjectType;
use App\Models\Institution;
use App\Models\MembershipClaim;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipClaim>
 */
class MembershipClaimFactory extends Factory
{
    protected $model = MembershipClaim::class;

    public function definition(): array
    {
        return [
            'subject_type' => MemberSubjectType::Institution,
            'subject_id' => Institution::factory(),
            'claimant_id' => User::factory(),
            'reviewer_id' => null,
            'status' => MembershipClaimStatus::Pending,
            'granted_role_slug' => null,
            'justification' => fake()->paragraph(),
            'reviewer_note' => null,
            'reviewed_at' => null,
            'cancelled_at' => null,
        ];
    }

    public function forInstitution(?Institution $institution = null): static
    {
        $institution ??= Institution::factory()->create();

        return $this->state([
            'subject_type' => MemberSubjectType::Institution,
            'subject_id' => $institution->getKey(),
        ]);
    }

    public function forSpeaker(?Speaker $speaker = null): static
    {
        $speaker ??= Speaker::factory()->create();

        return $this->state([
            'subject_type' => MemberSubjectType::Speaker,
            'subject_id' => $speaker->getKey(),
        ]);
    }
}
