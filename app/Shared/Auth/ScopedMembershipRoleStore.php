<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Account lifecycle store for team-scoped authorization rows.
 *
 * Authz tables are global infrastructure and stay outside feature ownership.
 * Membership uses this port so its lifecycle contributor can preserve scoped
 * role/permission rows without writing Authz storage itself.
 */
interface ScopedMembershipRoleStore
{
    /**
     * @return array{scoped_roles: list<array<string, mixed>>, scoped_permissions: list<array<string, mixed>>}
     */
    public function snapshot(string $accountId): array;

    public function detach(string $accountId): void;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(array $snapshot): void;
}
