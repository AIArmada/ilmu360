<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SlugRedirect;
use App\Models\User;

class SlugRedirectPolicy
{
    public function delete(User $user, SlugRedirect $redirect): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
