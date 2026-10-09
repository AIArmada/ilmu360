<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RewardAward;
use App\Models\User;

/**
 * Award authorization: staff decide, members read their own.
 *
 * - Staff (`super_admin`, `admin`) read every award; members read only
 *   awards where they are the recipient. List endpoints must scope
 *   member queries to the recipient — this policy is the second lock,
 *   not the only one.
 * - Every deciding action (review, approve, reverse, fraud dismissal)
 *   requires staff AND denies self-decisions on recipient-owned awards.
 */
class RewardAwardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RewardAward $award): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $award);
    }

    public function review(User $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function approve(User $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function reverse(User $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function dismissFraud(User $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function openAppeal(User $user, RewardAward $award): bool
    {
        return $this->isRecipient($user, $award);
    }

    private function canDecide(User $user, RewardAward $award): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }

        return ! $this->isRecipient($user, $award);
    }

    private function isRecipient(User $user, RewardAward $award): bool
    {
        return (string) $award->recipient_type === 'user'
            && (string) $award->recipient_id === (string) $user->getKey();
    }
}
