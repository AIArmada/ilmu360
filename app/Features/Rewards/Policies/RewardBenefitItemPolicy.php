<?php

declare(strict_types=1);

namespace App\Features\Rewards\Policies;

use AIArmada\Rewards\Models\RewardBenefitItem;
use App\Shared\Auth\AccountActor;

/**
 * Catalog authorization: active items are readable by every member;
 * creation and retirement are staff-only.
 */
class RewardBenefitItemPolicy
{
    public function viewAny(AccountActor $user): bool
    {
        return true;
    }

    public function view(AccountActor $user, RewardBenefitItem $item): bool
    {
        return true;
    }

    public function create(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function retire(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
