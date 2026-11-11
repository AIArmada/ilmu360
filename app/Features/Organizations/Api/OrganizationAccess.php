<?php

declare(strict_types=1);

namespace App\Features\Organizations\Api;

/**
 * Membership-aware access boundary for organizations.
 *
 * The package organization tables remain canonical; this contract only exposes
 * scalar identity, discovery and authorization to other features.
 */
interface OrganizationAccess
{
    /**
     * Organization selected for the current request, if any.
     */
    public function current(): ?OrganizationSummary;

    /**
     * Organization by primary key or public slug.
     */
    public function find(string $identifier): ?OrganizationSummary;

    /**
     * Organization by primary key or slug, only when the account is a member.
     */
    public function findForAccount(string $accountId, string $identifier): ?OrganizationSummary;

    /**
     * Organizations the account is a member of, ordered by name.
     *
     * @return list<OrganizationSummary>
     */
    public function accessibleFor(string $accountId): array;

    public function can(string $accountId, string $organizationId, string $ability): bool;
}
