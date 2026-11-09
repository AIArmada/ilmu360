<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\Authz\Facades\Authz;
use AIArmada\Authz\Models\Role;
use App\Enums\MemberSubjectType;
use App\Models\User;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Collection;

final readonly class MemberRoleCatalog
{
    public function __construct(
        private MemberRoleScopes $scopes,
        private MembershipDirectory $memberships,
    ) {}

    /**
     * @return array<string, string>
     */
    public function roleOptionsFor(MemberSubjectType $type): array
    {
        $scope = $this->scopeForType($type);

        return Authz::withScope($scope, function () use ($scope): array {
            $query = Role::query()->where('guard_name', 'web');

            if ($scope !== null) {
                $query->where('authz_scope_id', $scope->getKey());
            }

            return $query
                ->pluck('name', 'id')
                ->all();
        });
    }

    /**
     * @return list<string>
     */
    public function roleNamesFor(AccountActor $user, MemberSubjectType $type): array
    {
        $subjectId = $this->subjectIdFor($user, $type);

        if ($subjectId === null) {
            return [];
        }

        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($subjectId);

            /** @var Collection<int, Role> $roles */
            $roles = $user instanceof User ? $user->roles : new Collection;

            return $roles
                ->map(fn (Role $role): string => $role->name)
                ->values()
                ->all();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    private function subjectIdFor(AccountActor $user, MemberSubjectType $type): ?string
    {
        $subjectType = MembershipSubjectType::from($type->value);

        return $this->memberships->subjectIdsFor($user->actorId(), $subjectType)[0] ?? null;
    }

    private function scopeForType(MemberSubjectType $type): mixed
    {
        return match ($type) {
            MemberSubjectType::Institution => $this->scopes->institution(),
            MemberSubjectType::Person => $this->scopes->person(),
            MemberSubjectType::Event => $this->scopes->event(),
            MemberSubjectType::Reference => $this->scopes->reference(),
            MemberSubjectType::Organization => $this->scopes->organization(),
        };
    }
}
