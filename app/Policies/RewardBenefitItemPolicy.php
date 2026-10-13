<?php

declare(strict_types=1);

namespace App\Policies;

use AIArmada\Rewards\Models\RewardBenefitItem;
use App\Models\User;

/**
 * Catalog authorization: active items are readable by every member;
 * creation and retirement are staff-only.
 */
class RewardBenefitItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RewardBenefitItem $item): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function retire(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
