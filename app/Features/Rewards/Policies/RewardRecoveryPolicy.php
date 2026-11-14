<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Models\RewardRecovery;
use App\Shared\Auth\AccountActor;

/**
 * Recovery cases are finance-staff only: members never see receivable
 * internals. Opening, settlement, and writeoff each require staff; the
 * actions additionally require evidence and record the authorizer.
 */
class RewardRecoveryPolicy
{
    public function viewAny(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function view(AccountActor $user, RewardRecovery $recovery): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function open(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function settle(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function writeOff(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
