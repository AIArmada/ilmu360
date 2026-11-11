<?php

declare(strict_types=1);

namespace App\Features\Organizations\Api;

/**
 * Workspace snapshot for one authorized organization.
 */
final readonly class OrganizationDetail
{
    /**
     * @param  list<OrganizationMember>  $members
     */
    public function __construct(
        public OrganizationSummary $summary,
        public array $members,
    ) {}
}
