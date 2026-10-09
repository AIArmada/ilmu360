<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RewardRecovery;
use App\Models\User;

/**
 * Recovery cases are finance-staff only: members never see receivable
 * internals. Opening, settlement, and writeoff each require staff; the
 * actions additionally require evidence and record the authorizer.
 */
class RewardRecoveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function view(User $user, RewardRecovery $recovery): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function open(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function settle(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function writeOff(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
