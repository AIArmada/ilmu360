<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use AIArmada\Organizations\Models\Organization;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationDetail;
use App\Features\Organizations\Api\OrganizationMember;
use App\Features\Organizations\Api\OrganizationSummary;
use App\Features\Organizations\Api\OrganizationWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Interim organization workspace reader over the package aggregate.
 */
final readonly class OrganizationWorkspaceService implements OrganizationWorkspace
{
    public function __construct(
        private OrganizationAccess $access,
    ) {}

    public function organizationsFor(string $accountId): array
    {
        return $this->access->accessibleFor($accountId);
    }

    public function detailFor(string $accountId, string $organizationId): ?OrganizationDetail
    {
        $summary = $this->access->findForAccount($accountId, $organizationId);

        if (! $summary instanceof OrganizationSummary) {
            return null;
        }

        return new OrganizationDetail(
            summary: $summary,
            members: $this->membersFor((string) $summary->id),
        );
    }

    public function membersFor(string $organizationId, int $limit = 100): array
    {
        $organization = Organization::query()->find($organizationId);

        if (! $organization instanceof Organization) {
            return [];
        }

        return $organization->members()
            ->orderBy('name')
            ->limit(max(0, $limit))
            ->get()
            ->map(fn (Model $member): OrganizationMember => new OrganizationMember(
                accountId: (string) $member->getKey(),
                name: (string) $member->getAttribute('name'),
                email: $member->getAttribute('email'),
                role: (string) data_get($member->getRelationValue('pivot'), 'role'),
            ))
            ->all();
    }
}
