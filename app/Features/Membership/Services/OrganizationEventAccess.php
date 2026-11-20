<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use App\Features\Membership\Api\OrganizationEventAccessContract;
use App\Features\Organizations\Api\OrganizationAccess;

/**
 * Package-model adapter for organization event creation access.
 *
 * Organization access behaviour lives behind the Organizations feature's
 * scalar OrganizationAccess API; this boundary translates account and
 * organization ids for workspace entrypoints.
 */
final readonly class OrganizationEventAccess implements OrganizationEventAccessContract
{
    public function __construct(
        private OrganizationAccess $organizations,
    ) {}

    public function canCreate(string $accountId, string $organizationId): bool
    {
        return $this->organizations->canCreateEvent($accountId, $organizationId);
    }

    public function authorizeCreate(string $accountId, string $organizationId): void
    {
        $this->organizations->authorizeCreateEvent($accountId, $organizationId);
    }
}
