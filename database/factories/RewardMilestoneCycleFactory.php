<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardMilestoneStatus;
use App\Models\RewardMilestoneCycle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardMilestoneCycle>
 */
class RewardMilestoneCycleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipient_type' => 'user',
            'recipient_id' => Str::uuid(),
            'family_key' => 'family-'.Str::lower(Str::random(6)),
            'program_id' => null,
            'milestone_key' => 'milestone-'.Str::lower(Str::random(6)),
            'window_start' => now('UTC')->startOfDay(),
            'window_days' => 30,
            'target_count' => 3,
            'current_count' => 0,
            'reward_snapshot' => ['type' => 'points', 'points' => 10, 'unit' => 'pts'],
            'status' => RewardMilestoneStatus::Open,
            'award_id' => null,
            'achieved_at' => null,
            'invalidated_at' => null,
        ];
    }
}
