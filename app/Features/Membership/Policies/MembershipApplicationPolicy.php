<?php

declare(strict_types=1);

namespace App\Features\Membership\Policies;

use App\Features\Membership\Models\MembershipApplication;
use App\Models\User;

class MembershipApplicationPolicy
{
    public function delete(User $user, MembershipApplication $application): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
