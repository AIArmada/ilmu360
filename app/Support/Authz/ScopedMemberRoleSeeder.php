<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\FilamentAuthz\Facades\Authz;

final class ScopedMemberRoleSeeder
{
    public function __construct(
        private readonly MemberRoleScopes $scopes,
    ) {}

    public function ensureForInstitution(): void
    {
        $roles = ['owner', 'admin', 'editor', 'viewer'];

        Authz::withScope($this->scopes->institution(), function () use ($roles): void {
            $previousTeamId = getPermissionsTeamId();

            try {
                setPermissionsTeamId($this->scopes->institution()->getKey());

                foreach ($roles as $roleName) {
                    Role::findOrCreate($roleName, 'web');
                }
            } finally {
                setPermissionsTeamId($previousTeamId);
            }
        });
    }

    public function ensureForSpeaker(): void
    {
        // ponytail: same role set as institution, add speaker-specific roles when needed
        $this->ensureForInstitution();
    }

    public function ensureForEvent(): void
    {
        // ponytail: same role set as institution, add event-specific roles when needed
        $this->ensureForInstitution();
    }
}
