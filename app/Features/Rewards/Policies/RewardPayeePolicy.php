<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Models\RewardPayee;
use App\Shared\Auth\AccountActor;

/**
 * Payee authorization: staff verify and read; members read only their own
 * masked destinations. Verification itself additionally requires a
 * verifier independent of the recipient (enforced in the action).
 */
class RewardPayeePolicy
{
    public function viewAny(AccountActor $user): bool
    {
        return true;
    }

    public function view(AccountActor $user, RewardPayee $payee): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return (string) $payee->recipient_type === $user->actorType()
            && (string) $payee->recipient_id === (string) $user->actorId();
    }

    public function verify(AccountActor $user, ?RewardPayee $payee = null): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }

        if ($payee instanceof RewardPayee) {
            return (string) $payee->recipient_type !== $user->actorType() || (string) $payee->recipient_id !== (string) $user->actorId();
        }

        return true;
    }

    public function register(AccountActor $user): bool
    {
        return true;
    }

    public function reject(AccountActor $user, ?RewardPayee $payee = null): bool
    {
        return $this->verify($user, $payee);
    }
}
