<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Policies;

use App\Features\SlugRedirects\Models\SlugRedirect;
use App\Shared\Auth\AccountActor;

class SlugRedirectPolicy
{
    public function delete(AccountActor $user, SlugRedirect $redirect): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(AccountActor $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
