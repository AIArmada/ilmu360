<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RewardPayee;
use App\Models\User;

/**
 * Payee authorization: staff verify and read; members read only their own
 * masked destinations. Verification itself additionally requires a
 * verifier independent of the recipient (enforced in the action).
 */
class RewardPayeePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RewardPayee $payee): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return (string) $payee->recipient_type === 'user'
            && (string) $payee->recipient_id === (string) $user->getKey();
    }

    public function verify(User $user, ?RewardPayee $payee = null): bool
    {
        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            return false;
        }

        if ($payee instanceof RewardPayee) {
            return ! ((string) $payee->recipient_type === 'user'
                && (string) $payee->recipient_id === (string) $user->getKey());
        }

        return true;
    }

    public function register(User $user): bool
    {
        return true;
    }

    public function reject(User $user, ?RewardPayee $payee = null): bool
    {
        return $this->verify($user, $payee);
    }
}
