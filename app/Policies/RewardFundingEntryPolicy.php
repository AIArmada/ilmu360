<?php

declare(strict_types=1);

namespace App\Policies;

use AIArmada\Rewards\Models\RewardFundingEntry;
use App\Models\User;

/**
 * Funding entries are finance-staff only: members never read or write
 * program budgets. Recording requires staff; entries are immutable after.
 */
class RewardFundingEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function view(User $user, RewardFundingEntry $entry): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
