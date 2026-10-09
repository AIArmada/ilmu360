<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Models\RewardSourceCoordinator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardSourceCoordinator>
 */
class RewardSourceCoordinatorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_business_key' => 'src-'.Str::lower(Str::random(8)),
            'component' => RewardComponent::Points,
            'beneficiary_role' => RewardBeneficiaryRole::Recipient,
        ];
    }
}
