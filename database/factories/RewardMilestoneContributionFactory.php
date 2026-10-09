<?php

namespace Database\Factories;

use App\Models\RewardMilestoneContribution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardMilestoneContribution>
 */
class RewardMilestoneContributionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cycle_id' => Str::uuid(),
            'source_business_key' => 'src-'.Str::lower(Str::random(8)),
            'contributor_type' => 'user',
            'contributor_id' => (string) Str::uuid(),
            'contributor_role' => 'recipient',
            'outcome' => 'event_checkin',
            'source_snapshot' => ['source_business_key' => 'src-fixture'],
            'occurred_at' => now('UTC'),
            'contributed_at' => now('UTC'),
            'revoked_at' => null,
            'revoke_reason' => null,
        ];
    }
}
