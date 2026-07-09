<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Space;
use App\Models\User;

class SpacePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Space $space): bool
    {
        if ((string) $space->status === 'active' && (string) $space->visibility === 'public') {
            return true;
        }

        return $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(User $user, Space $space): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(User $user, Space $space): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, Space $space): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Space $space): bool
    {
        return $user->hasRole('super_admin');
    }
}
