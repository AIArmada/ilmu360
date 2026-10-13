<?php

declare(strict_types=1);

namespace App\Policies;

use AIArmada\Rewards\Models\RewardBenefitGrant;
use App\Models\User;

/**
 * Grant authorization: members read, redeem, cancel, and consume their
 * own grants; fulfillment and revocation are staff-only. Stock and point
 * movements stay staff-visible; members see status, quantities, and
 * their own evidence.
 */
class RewardBenefitGrantPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RewardBenefitGrant $grant): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $grant);
    }

    public function redeem(User $user, string $recipientId): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return (string) $user->getKey() === $recipientId;
    }

    public function cancel(User $user, RewardBenefitGrant $grant): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $grant);
    }

    public function fulfill(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function consume(User $user, RewardBenefitGrant $grant): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return $this->isRecipient($user, $grant);
    }

    public function revoke(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function isRecipient(User $user, RewardBenefitGrant $grant): bool
    {
        return (string) $grant->recipient_type === 'user'
            && (string) $grant->recipient_id === (string) $user->getKey();
    }
}
