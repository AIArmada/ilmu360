<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\CommerceSupport\Models\Role;

final class ScopedMemberRoleSeeder
{
    public function ensureForInstitution(): void
    {
        $roles = ['admin', 'editor', 'viewer'];

        foreach ($roles as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }
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
