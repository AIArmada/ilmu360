<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Policies;

use App\Features\Inspirations\Models\Inspiration;
use App\Shared\Auth\AccountActor;

class InspirationPolicy
{
    public function viewAny(?AccountActor $user): bool
    {
        return true;
    }

    public function view(?AccountActor $user, Inspiration $inspiration): bool
    {
        if ((string) $inspiration->status === 'active') {
            return true;
        }

        return $user instanceof AccountActor
            && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function create(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function update(AccountActor $user, Inspiration $inspiration): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    public function delete(AccountActor $user, Inspiration $inspiration): bool
    {
        return $user->hasAnyRole(['super_admin']);
    }

    public function deleteAny(AccountActor $user): bool
    {
        return $user->hasAnyRole(['super_admin']);
    }

    public function restore(AccountActor $user, Inspiration $inspiration): bool
    {
        return $user->hasAnyRole(['super_admin']);
    }

    public function forceDelete(AccountActor $user, Inspiration $inspiration): bool
    {
        return $user->hasAnyRole(['super_admin']);
    }
}
