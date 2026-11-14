<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Models\RewardAward;
use App\Shared\Auth\AccountActor;

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
    public function viewAny(AccountActor $user): bool
    {
        return true;
    }

    public function view(AccountActor $user, RewardAward $award): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $award);
    }

    public function review(AccountActor $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function requestPayout(AccountActor $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function fulfill(AccountActor $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function approve(AccountActor $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function reverse(AccountActor $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function dismissFraud(AccountActor $user, RewardAward $award): bool
    {
        return $this->canDecide($user, $award);
    }

    public function openAppeal(AccountActor $user, RewardAward $award): bool
    {
        return $this->isRecipient($user, $award);
    }

    private function canDecide(AccountActor $user, RewardAward $award): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }

        return ! $this->isRecipient($user, $award);
    }

    private function isRecipient(AccountActor $user, RewardAward $award): bool
    {
        return (string) $award->recipient_type === $user->actorType()
            && (string) $award->recipient_id === (string) $user->actorId();
    }
}
