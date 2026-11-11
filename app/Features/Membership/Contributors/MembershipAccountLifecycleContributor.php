<?php

declare(strict_types=1);

namespace App\Features\Membership\Contributors;

use App\Features\Membership\Actions\MembershipAccountStateAction;
use App\Shared\Auth\AccountLifecycleContributor;
use App\Shared\Auth\ScopedMembershipRoleStore;

/**
 * Preserves membership state across account deletion and restoration.
 *
 * The feature owns institution/person/reference/event member pivots and the
 * membership-application applicant/reviewer attribution. Team-scoped
 * authorization rows are delegated to the Authz support adapter because Authz
 * tables remain global infrastructure and are never feature-owned. Global
 * (team-null) role and permission rows remain in the conventional Authz
 * restoration path in HasUserRestoration.
 *
 * Pivot snapshots include the role column, so a restored admin/editor/owner
 * keeps their membership role instead of falling back to the pivot default.
 * Snapshots captured before the role fix cannot recover a role that was never
 * stored; new captures always include it.
 */
final readonly class MembershipAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function __construct(
        private MembershipAccountStateAction $accountState,
        private ScopedMembershipRoleStore $scopedRoles,
    ) {}

    public function key(): string
    {
        return 'membership';
    }

    public function priority(): int
    {
        return 90;
    }

    public function snapshot(string $accountId): array
    {
        return $this->accountState->snapshot($accountId) + $this->scopedRoles->snapshot($accountId);
    }

    public function detach(string $accountId): void
    {
        $this->accountState->detach($accountId);
        $this->scopedRoles->detach($accountId);
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $this->accountState->restore($accountId, $snapshot);

        $this->scopedRoles->restore([
            'scoped_roles' => $snapshot['scoped_roles'] ?? [],
            'scoped_permissions' => $snapshot['scoped_permissions'] ?? [],
        ]);
    }

    public function afterRestore(string $accountId): void {}
}
