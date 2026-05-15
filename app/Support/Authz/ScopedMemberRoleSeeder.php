<?php

namespace App\Support\Authz;

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\AuthzScope;
use AIArmada\FilamentAuthz\Models\Permission;
use AIArmada\FilamentAuthz\Models\Role;
use App\Enums\MemberSubjectType;

class ScopedMemberRoleSeeder
{
    public function __construct(
        private readonly MemberRoleScopes $memberRoleScopes,
        private readonly MemberRoleCatalog $memberRoleCatalog,
    ) {}

    public function ensureForInstitution(): void
    {
        $this->ensure(MemberSubjectType::Institution);
    }

    public function ensureForSpeaker(): void
    {
        $this->ensure(MemberSubjectType::Speaker);
    }

    public function ensureForEvent(): void
    {
        $this->ensure(MemberSubjectType::Event);
    }

    public function ensureForReference(): void
    {
        $this->ensure(MemberSubjectType::Reference);
    }

    public function ensure(MemberSubjectType $subjectType): void
    {
        $this->seedRolesForScope(
            $subjectType->authzScope($this->memberRoleScopes),
            $this->memberRoleCatalog->permissionMapFor($subjectType),
            false,
        );
    }

    /**
     * @param  array<string, list<string>>  $rolePermissions
     */
    private function seedRolesForScope(AuthzScope $scope, array $rolePermissions, bool $syncExisting): void
    {
        $allPermissions = collect($rolePermissions)
            ->flatten()
            ->unique()
            ->values()
            ->all();

        $this->ensurePermissions($allPermissions);

        Authz::withScope($scope, function () use ($rolePermissions, $syncExisting): void {
            foreach ($rolePermissions as $roleName => $permissions) {
                $role = Role::findOrCreate($roleName, 'web');

                if (! $syncExisting && $role->permissions()->exists()) {
                    continue;
                }

                $role->syncPermissions($permissions);
            }
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function ensurePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }
}
