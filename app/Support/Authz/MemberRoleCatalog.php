<?php

namespace App\Support\Authz;

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Enums\MemberSubjectType;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

final readonly class MemberRoleCatalog
{
    public function __construct(
        private MemberRoleScopes $memberRoleScopes,
    ) {}

    /**
     * @return array<string, array{label: string, description: string, permissions: list<string>, protected: bool}>
     */
    public function definitionsFor(MemberSubjectType $subjectType): array
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => [
                'owner' => [
                    'label' => 'Owner',
                    'description' => 'Full institution control, including member management and event operations.',
                    'permissions' => [
                        'institution.view',
                        'institution.update',
                        'institution.delete',
                        'institution.manage-members',
                        'institution.manage-donation-channels',
                        'event.view',
                        'event.approve',
                        'event.create',
                        'event.update',
                        'event.delete',
                        'event.manage-members',
                        'event.view-registrations',
                        'event.export-registrations',
                    ],
                    'protected' => true,
                ],
                'admin' => [
                    'label' => 'Admin',
                    'description' => 'Manage the institution and its events without delete ownership.',
                    'permissions' => [
                        'institution.view',
                        'institution.update',
                        'institution.manage-members',
                        'institution.manage-donation-channels',
                        'event.view',
                        'event.approve',
                        'event.create',
                        'event.update',
                        'event.delete',
                        'event.manage-members',
                        'event.view-registrations',
                        'event.export-registrations',
                    ],
                    'protected' => false,
                ],
                'editor' => [
                    'label' => 'Editor',
                    'description' => 'Update the institution and contribute on events.',
                    'permissions' => [
                        'institution.view',
                        'event.view',
                        'event.approve',
                        'event.create',
                        'event.update',
                    ],
                    'protected' => false,
                ],
                'viewer' => [
                    'label' => 'Viewer',
                    'description' => 'Read-only access to institution and event records.',
                    'permissions' => [
                        'institution.view',
                        'event.view',
                    ],
                    'protected' => false,
                ],
            ],
            MemberSubjectType::Speaker => [
                'owner' => [
                    'label' => 'Owner',
                    'description' => 'Full speaker profile control, including member management.',
                    'permissions' => [
                        'speaker.view',
                        'speaker.update',
                        'speaker.delete',
                        'speaker.manage-members',
                        'event.view',
                        'event.approve',
                        'event.update',
                    ],
                    'protected' => true,
                ],
                'admin' => [
                    'label' => 'Admin',
                    'description' => 'Manage the speaker profile and member access.',
                    'permissions' => [
                        'speaker.view',
                        'speaker.update',
                        'speaker.manage-members',
                        'event.view',
                        'event.approve',
                        'event.update',
                    ],
                    'protected' => false,
                ],
                'editor' => [
                    'label' => 'Editor',
                    'description' => 'Update the speaker profile.',
                    'permissions' => [
                        'speaker.view',
                        'speaker.update',
                        'event.view',
                        'event.approve',
                        'event.update',
                    ],
                    'protected' => false,
                ],
                'viewer' => [
                    'label' => 'Viewer',
                    'description' => 'Read-only access to the speaker profile.',
                    'permissions' => [
                        'speaker.view',
                        'event.view',
                    ],
                    'protected' => false,
                ],
            ],
            MemberSubjectType::Event => [
                'organizer' => [
                    'label' => 'Organizer',
                    'description' => 'Full event control, including registrations and team members.',
                    'permissions' => [
                        'event.view',
                        'event.update',
                        'event.delete',
                        'event.manage-members',
                        'event.view-registrations',
                        'event.export-registrations',
                    ],
                    'protected' => true,
                ],
                'co-organizer' => [
                    'label' => 'Co-organizer',
                    'description' => 'Manage the event and its team without delete access.',
                    'permissions' => [
                        'event.view',
                        'event.update',
                        'event.manage-members',
                        'event.view-registrations',
                        'event.export-registrations',
                    ],
                    'protected' => false,
                ],
                'editor' => [
                    'label' => 'Editor',
                    'description' => 'Update event details.',
                    'permissions' => [
                        'event.view',
                        'event.update',
                    ],
                    'protected' => false,
                ],
                'viewer' => [
                    'label' => 'Viewer',
                    'description' => 'Read-only access to the event.',
                    'permissions' => [
                        'event.view',
                    ],
                    'protected' => false,
                ],
            ],
            MemberSubjectType::Reference => [
                'owner' => [
                    'label' => 'Owner',
                    'description' => 'Full reference control, including member management.',
                    'permissions' => [
                        'reference.view',
                        'reference.update',
                        'reference.delete',
                        'reference.manage-members',
                        'reference.approve',
                    ],
                    'protected' => true,
                ],
                'admin' => [
                    'label' => 'Admin',
                    'description' => 'Manage the reference and member access.',
                    'permissions' => [
                        'reference.view',
                        'reference.update',
                        'reference.manage-members',
                        'reference.approve',
                    ],
                    'protected' => false,
                ],
                'editor' => [
                    'label' => 'Editor',
                    'description' => 'Update the reference.',
                    'permissions' => [
                        'reference.view',
                        'reference.update',
                    ],
                    'protected' => false,
                ],
                'viewer' => [
                    'label' => 'Viewer',
                    'description' => 'Read-only access to the reference.',
                    'permissions' => [
                        'reference.view',
                    ],
                    'protected' => false,
                ],
            ],
        };
    }

    /**
     * @return array<string, list<string>>
     */
    public function permissionMapFor(MemberSubjectType $subjectType): array
    {
        return collect($this->definitionsFor($subjectType))
            ->mapWithKeys(fn (array $definition, string $roleName): array => [$roleName => $definition['permissions']])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function roleOptionsFor(MemberSubjectType $subjectType): array
    {
        $teamsKey = app(PermissionRegistrar::class)->teamsKey;
        $scope = $subjectType->authzScope($this->memberRoleScopes);

        return Authz::withScope($scope, fn (): array => Role::query()
            ->where($teamsKey, getPermissionsTeamId())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all());
    }

    /**
     * @return array<string, string>
     */
    public function invitationRoleSlugOptionsFor(MemberSubjectType $subjectType): array
    {
        $options = [];

        foreach ($this->definitionsFor($subjectType) as $roleSlug => $definition) {
            if ($definition['protected']) {
                continue;
            }

            $options[$roleSlug] = $definition['label'];
        }

        return $options;
    }

    public function isInvitableRole(MemberSubjectType $subjectType, string $roleSlug): bool
    {
        return array_key_exists($roleSlug, $this->invitationRoleSlugOptionsFor($subjectType));
    }

    /**
     * @return array<string, string>
     */
    public function membershipClaimRoleSlugOptionsFor(MemberSubjectType $subjectType): array
    {
        $options = [];

        foreach ($this->definitionsFor($subjectType) as $roleSlug => $definition) {
            if (! in_array($roleSlug, ['editor', 'admin', 'owner'], true)) {
                continue;
            }

            $options[$roleSlug] = $definition['label'];
        }

        return $options;
    }

    public function isMembershipClaimRole(MemberSubjectType $subjectType, string $roleSlug): bool
    {
        return array_key_exists($roleSlug, $this->membershipClaimRoleSlugOptionsFor($subjectType));
    }

    /**
     * @return list<string>
     */
    public function roleIdsFor(User $user, MemberSubjectType $subjectType): array
    {
        $scope = $subjectType->authzScope($this->memberRoleScopes);

        return Authz::withScope($scope, fn (): array => $user->roles()->pluck('id')->all(), $user);
    }

    /**
     * @return list<string>
     */
    public function roleNamesFor(User $user, MemberSubjectType $subjectType): array
    {
        $scope = $subjectType->authzScope($this->memberRoleScopes);

        return Authz::withScope($scope, fn (): array => $user->getRoleNames()->values()->all(), $user);
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function userHasAnyRole(User $user, MemberSubjectType $subjectType, array $roleNames): bool
    {
        $scope = $subjectType->authzScope($this->memberRoleScopes);

        return Authz::withScope(
            $scope,
            fn (): bool => $user->hasAnyRole($roleNames),
            $user,
        );
    }

    public function userHasRole(User $user, MemberSubjectType $subjectType, string $roleName): bool
    {
        $scope = $subjectType->authzScope($this->memberRoleScopes);

        return Authz::withScope(
            $scope,
            fn (): bool => $user->hasRole($roleName),
            $user,
        );
    }

    public function currentRoleName(User $user, MemberSubjectType $subjectType): ?string
    {
        return $this->roleNamesFor($user, $subjectType)[0] ?? null;
    }

    public function userHasProtectedRole(User $user, MemberSubjectType $subjectType): bool
    {
        $roleName = $this->currentRoleName($user, $subjectType);

        return $roleName !== null && $this->isProtectedRole($subjectType, $roleName);
    }

    public function primaryRoleName(MemberSubjectType $subjectType): string
    {
        return $subjectType->primaryRoleName();
    }

    public function isProtectedRole(MemberSubjectType $subjectType, string $roleName): bool
    {
        return (bool) Arr::get($this->definitionsFor($subjectType), "{$roleName}.protected", false);
    }

    public function roleLabel(MemberSubjectType $subjectType, string $roleName): string
    {
        return (string) Arr::get(
            $this->definitionsFor($subjectType),
            "{$roleName}.label",
            Str::headline($roleName),
        );
    }

    public function resolveRoleId(MemberSubjectType $subjectType, ?string $roleIdentifier): ?string
    {
        $role = $this->resolveRole($subjectType, $roleIdentifier);

        if (! $role instanceof Role) {
            return null;
        }

        return (string) $role->getKey();
    }

    public function resolveRoleName(MemberSubjectType $subjectType, ?string $roleIdentifier): ?string
    {
        $role = $this->resolveRole($subjectType, $roleIdentifier);

        if (! $role instanceof Role) {
            return null;
        }

        return (string) $role->name;
    }

    private function resolveRole(MemberSubjectType $subjectType, ?string $roleIdentifier): ?Role
    {
        if ($roleIdentifier === null || $roleIdentifier === '') {
            return null;
        }

        $teamsKey = app(PermissionRegistrar::class)->teamsKey;
        $scope = $subjectType->authzScope($this->memberRoleScopes);

        /** @var Role|null $role */
        $role = Authz::withScope($scope, function () use ($roleIdentifier, $teamsKey): ?Role {
            $query = Role::query()
                ->where($teamsKey, getPermissionsTeamId());

            if (Str::isUuid($roleIdentifier)) {
                $query->whereKey($roleIdentifier);
            } else {
                $query->where('name', $roleIdentifier);
            }

            return $query->first();
        });

        if (! $role instanceof Role) {
            throw new InvalidArgumentException("Role [{$roleIdentifier}] is not valid for {$subjectType->value} members.");
        }

        return $role;
    }
}
