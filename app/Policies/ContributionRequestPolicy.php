<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContributionRequest;
use App\Models\User;

class ContributionRequestPolicy
{
    public function delete(User $user, ContributionRequest $request): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
