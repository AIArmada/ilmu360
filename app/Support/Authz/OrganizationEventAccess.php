<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\Organizations\Models\Organization;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Models\User;

/**
 * Package-model adapter for organization event creation access.
 *
 * Organization access behaviour lives behind the Organizations feature's
 * scalar OrganizationAccess API; package-aware callers keep this model
 * signature until their own slice moves them onto the feature boundary.
 */
final readonly class OrganizationEventAccess
{
    public function __construct(
        private OrganizationAccess $organizations,
    ) {}

    public function canCreate(User $user, Organization $organization): bool
    {
        return $this->organizations->canCreateEvent(
            (string) $user->getKey(),
            (string) $organization->getKey(),
        );
    }

    public function authorizeCreate(User $user, Organization $organization): void
    {
        $this->organizations->authorizeCreateEvent(
            (string) $user->getKey(),
            (string) $organization->getKey(),
        );
    }
}
