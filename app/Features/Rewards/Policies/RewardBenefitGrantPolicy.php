<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Models\RewardBenefitGrant;
use App\Shared\Auth\AccountActor;

/**
 * Grant authorization: members read, redeem, cancel, and consume their
 * own grants; fulfillment and revocation are staff-only. Stock and point
 * movements stay staff-visible; members see status, quantities, and
 * their own evidence.
 */
class RewardBenefitGrantPolicy
{
    public function viewAny(AccountActor $user): bool
    {
        return true;
    }

    public function view(AccountActor $user, RewardBenefitGrant $grant): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $grant);
    }

    public function redeem(AccountActor $user, string $recipientId): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $user->actorId() === $recipientId;
    }

    public function cancel(AccountActor $user, RewardBenefitGrant $grant): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $grant);
    }

    public function fulfill(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function consume(AccountActor $user, RewardBenefitGrant $grant): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $grant);
    }

    public function revoke(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function isRecipient(AccountActor $user, RewardBenefitGrant $grant): bool
    {
        return (string) $grant->recipient_type === $user->actorType()
            && (string) $grant->recipient_id === $user->actorId();
    }
}
