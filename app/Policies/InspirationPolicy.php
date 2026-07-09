<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Inspiration;
use App\Models\User;

class InspirationPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Inspiration $inspiration): bool
    {
        if ((string) $inspiration->status === 'active') {
            return true;
        }

        return $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(User $user, Inspiration $inspiration): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(User $user, Inspiration $inspiration): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, Inspiration $inspiration): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Inspiration $inspiration): bool
    {
        return $user->hasRole('super_admin');
    }
}
