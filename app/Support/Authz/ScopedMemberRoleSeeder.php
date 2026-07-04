<?php

namespace App\Support\Authz;

use AIArmada\CommerceSupport\Models\AuthzScope;
use AIArmada\CommerceSupport\Models\Permission;
use AIArmada\CommerceSupport\Models\Role;
use AIArmada\FilamentAuthz\Facades\Authz;
use App\Enums\MemberSubjectType;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

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
        $teamsKey = app(PermissionRegistrar::class)->teamsKey;

        Authz::withScope($scope, function () use ($rolePermissions, $syncExisting, $teamsKey, $allPermissions): void {
            $this->ensurePermissions($allPermissions);
            $scopeTeamId = getPermissionsTeamId();

            foreach ($rolePermissions as $roleName => $permissions) {
                $roleAttributes = [
                    'name' => $roleName,
                    'guard_name' => 'web',
                ];

                if (is_string($teamsKey) && $teamsKey !== '') {
                    $roleAttributes[$teamsKey] = $scopeTeamId;
                }

                /** @var Role $role */
                $role = Role::query()->firstOrNew($roleAttributes);

                if (! $role->exists) {
                    $role->forceFill([
                        'id' => (string) Str::uuid(),
                    ])->save();
                }

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
