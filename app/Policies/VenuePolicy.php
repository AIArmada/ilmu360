<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Venue;

class VenuePolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Venue $venue): bool
    {
        if ($venue->status === 'verified' && (string) $venue->visibility === 'public') {
            return true;
        }

        return $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(User $user, Venue $venue): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(User $user, Venue $venue): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, Venue $venue): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Venue $venue): bool
    {
        return $user->hasRole('super_admin');
    }
}
