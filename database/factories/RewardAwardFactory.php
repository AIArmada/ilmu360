<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Models\RewardAward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardAward>
 */
class RewardAwardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'program_id' => Str::uuid(),
            'policy_version_id' => Str::uuid(),
            'enrollment_id' => null,
            'acceptance_id' => null,
            'recipient_type' => 'user',
            'recipient_id' => Str::uuid(),
            'beneficiary_role' => RewardBeneficiaryRole::Recipient,
            'source_business_key' => 'src-'.Str::lower(Str::random(8)),
            'family_key' => 'family-'.Str::lower(Str::random(6)),
            'component' => RewardComponent::Points,
            'status' => RewardAwardStatus::Held,
            'amount_snapshot' => ['type' => 'points', 'points' => 10, 'unit' => 'pts'],
            'policy_snapshot' => ['policy_version' => 1],
            'consent_snapshot' => ['acceptance' => 'fixture'],
            'source_snapshot' => ['source_business_key' => 'src-fixture'],
            'held_at' => now('UTC'),
            'pending_review_at' => null,
            'approved_at' => null,
            'rejected_at' => null,
            'fulfilled_at' => null,
            'reversed_at' => null,
            'corrected_at' => null,
            'acknowledged_at' => null,
        ];
    }
}
