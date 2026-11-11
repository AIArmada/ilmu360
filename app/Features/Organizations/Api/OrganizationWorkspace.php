<?php

declare(strict_types=1);

namespace App\Features\Organizations\Api;

/**
 * Read composition for organization workspace surfaces.
 */
interface OrganizationWorkspace
{
    /**
     * @return list<OrganizationSummary>
     */
    public function organizationsFor(string $accountId): array;

    /**
     * Authorized workspace detail, or null when the account is not a member.
     */
    public function detailFor(string $accountId, string $organizationId): ?OrganizationDetail;

    /**
     * @return list<OrganizationMember>
     */
    public function membersFor(string $organizationId, int $limit = 100): array;
}
