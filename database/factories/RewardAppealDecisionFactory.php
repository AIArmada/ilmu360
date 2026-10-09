<?php

namespace Database\Factories;

use App\Models\RewardAppealDecision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardAppealDecision>
 */
class RewardAppealDecisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appeal_id' => Str::uuid(),
            'seq' => 1,
            'from_status' => 'open',
            'to_status' => 'open',
            'actor_type' => 'user',
            'actor_id' => (string) Str::uuid(),
            'actor_role' => 'member',
            'reason' => 'test-decision',
            'evidence' => null,
            'decided_at' => now('UTC'),
        ];
    }
}
