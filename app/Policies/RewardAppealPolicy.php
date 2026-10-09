<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RewardAppeal;
use App\Models\RewardAward;
use App\Models\User;

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
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RewardAppeal $appeal): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isInvolved($user, $appeal);
    }

    public function create(User $user, RewardAward $award): bool
    {
        return (string) $award->recipient_type === 'user'
            && (string) $award->recipient_id === (string) $user->getKey();
    }

    public function decide(User $user, RewardAppeal $appeal): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }

        $award = RewardAward::query()->whereKey((string) $appeal->award_id)->first();

        return ! ($award instanceof RewardAward
            && (string) $award->recipient_type === 'user'
            && (string) $award->recipient_id === (string) $user->getKey());
    }

    private function isInvolved(User $user, RewardAppeal $appeal): bool
    {
        $userId = (string) $user->getKey();

        if ((string) $appeal->opened_by_type === 'user' && (string) $appeal->opened_by_id === $userId) {
            return true;
        }

        $award = RewardAward::query()->whereKey((string) $appeal->award_id)->first();

        return $award instanceof RewardAward
            && (string) $award->recipient_type === 'user'
            && (string) $award->recipient_id === $userId;
    }
}
