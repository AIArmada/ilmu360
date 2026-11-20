<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

/**
 * Organization event-creation access for account and organization ids.
 *
 * The organization package owns permission storage; this boundary is the
 * feature-level answer used by organization workspaces.
 */
interface OrganizationEventAccessContract
{
    public function canCreate(string $accountId, string $organizationId): bool;

    public function authorizeCreate(string $accountId, string $organizationId): void;
}
