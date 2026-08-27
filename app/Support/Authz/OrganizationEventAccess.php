<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\Organizations\Enums\OrganizationStatus;
use AIArmada\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class OrganizationEventAccess
{
    public function canCreate(User $user, Organization $organization): bool
    {
        return $organization->status === OrganizationStatus::Active
            && $organization->members()->whereKey($user->getKey())->exists();
    }

    public function authorizeCreate(User $user, Organization $organization): void
    {
        if (! $this->canCreate($user, $organization)) {
            throw new AuthorizationException('The actor cannot create events for this organization.');
        }
    }
}
