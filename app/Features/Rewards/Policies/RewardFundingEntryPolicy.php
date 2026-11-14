<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Models\RewardFundingEntry;
use App\Shared\Auth\AccountActor;

/**
 * Funding entries are finance-staff only: members never read or write
 * program budgets. Recording requires staff; entries are immutable after.
 */
class RewardFundingEntryPolicy
{
    public function viewAny(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function view(AccountActor $user, RewardFundingEntry $entry): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function create(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
