<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardAppealStatus;
use App\Models\RewardAppeal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardAppeal>
 */
class RewardAppealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'award_id' => Str::uuid(),
            'seq' => 1,
            'status' => RewardAppealStatus::Open,
            'opened_by_type' => 'user',
            'opened_by_id' => (string) Str::uuid(),
            'opened_by_role' => 'member',
            'reason' => 'test-appeal',
            'evidence' => null,
            'window_ends_at' => now('UTC')->addDays(30),
            'opened_at' => now('UTC'),
            'in_review_at' => null,
            'upheld_at' => null,
            'overturned_at' => null,
            'closed_at' => null,
        ];
    }
}
