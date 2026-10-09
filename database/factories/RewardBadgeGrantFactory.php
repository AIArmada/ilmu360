<?php

namespace Database\Factories;

use App\Enums\Rewards\RewardBadgeStatus;
use App\Models\RewardBadgeGrant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardBadgeGrant>
 */
class RewardBadgeGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recipient_type' => 'user',
            'recipient_id' => Str::uuid(),
            'family_key' => 'family-'.Str::lower(Str::random(6)),
            'badge_key' => 'badge-'.Str::lower(Str::random(6)),
            'seq' => 1,
            'program_id' => null,
            'award_id' => null,
            'status' => RewardBadgeStatus::Granted,
            'granted_by_type' => 'system',
            'granted_by_id' => null,
            'grant_reason' => 'test-grant',
            'evidence' => null,
            'granted_at' => now('UTC'),
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revoke_reason' => null,
            'revoked_at' => null,
        ];
    }
}
