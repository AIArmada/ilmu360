<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Series;
use App\Models\User;

class SeriesPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Series $series): bool
    {
        if ($series->visibility === 'public' && $series->is_active) {
            return true;
        }

        return $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(User $user, Series $series): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(User $user, Series $series): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, Series $series): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Series $series): bool
    {
        return $user->hasRole('super_admin');
    }
}
