<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAward;
use App\Shared\Auth\AccountActor;

/**
 * Appeal authorization: members open and read their own, staff decide.
 *
 * - Staff (`super_admin`, `admin`) read and decide every appeal, except
 *   appeals on recipient-owned awards (no self-decisions).
 * - Members open appeals only on their own awards and read only appeals
 *   they opened or that concern their awards.
 */
class RewardAppealPolicy
{
    public function viewAny(AccountActor $user): bool
    {
        return true;
    }

    public function view(AccountActor $user, RewardAppeal $appeal): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isInvolved($user, $appeal);
    }

    public function create(AccountActor $user, ?RewardAward $award = null): bool
    {
        return $award instanceof RewardAward
            && (string) $award->recipient_type === $user->actorType()
            && (string) $award->recipient_id === $user->actorId();
    }

    public function decide(AccountActor $user, RewardAppeal $appeal): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }

        $award = RewardAward::query()->whereKey((string) $appeal->award_id)->first();

        return ! ($award instanceof RewardAward
            && (string) $award->recipient_type === $user->actorType()
            && (string) $award->recipient_id === $user->actorId());
    }

    private function isInvolved(AccountActor $user, RewardAppeal $appeal): bool
    {
        $userId = $user->actorId();

        if ((string) $appeal->opened_by_type === $user->actorType() && (string) $appeal->opened_by_id === $userId) {
            return true;
        }

        $award = RewardAward::query()->whereKey((string) $appeal->award_id)->first();

        return $award instanceof RewardAward
            && (string) $award->recipient_type === $user->actorType()
            && (string) $award->recipient_id === $userId;
    }
}
