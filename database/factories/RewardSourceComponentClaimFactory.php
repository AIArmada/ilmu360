<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Models\RewardSourceComponentClaim;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardSourceComponentClaim>
 */
class RewardSourceComponentClaimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_business_key' => 'src-'.Str::lower(Str::random(8)),
            'component' => RewardComponent::Points,
            'beneficiary_role' => RewardBeneficiaryRole::Recipient,
            'family_key' => 'family-'.Str::lower(Str::random(6)),
            'program_id' => Str::uuid(),
            'award_id' => Str::uuid(),
            'compatible_snapshot' => [],
            'claimed_at' => now('UTC'),
        ];
    }
}
