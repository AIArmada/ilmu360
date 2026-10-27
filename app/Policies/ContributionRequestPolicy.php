<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ContributionRequestType;
use App\Models\ContributionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ContributionRequestPolicy
{
    public function review(User $user, ContributionRequest $request): bool
    {
        if ($user->hasAnyRole(['super_admin', 'admin', 'moderator'])) {
            return true;
        }

        if ($request->type === ContributionRequestType::Create) {
            return false;
        }

        return $request->entity instanceof Model
            && $user->can('update', $request->entity);
    }

    public function delete(User $user, ContributionRequest $request): bool
    {
        return $user->hasRole('super_admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
