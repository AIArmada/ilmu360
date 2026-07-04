<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Tag $tag): bool
    {
        if ($tag->status === 'verified') {
            return true;
        }

        return $user instanceof User
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->hasRole('super_admin');
    }

    public function restore(User $user, Tag $tag): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Tag $tag): bool
    {
        return $user->hasRole('super_admin');
    }
}
