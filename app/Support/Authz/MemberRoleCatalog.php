<?php

declare(strict_types=1);

namespace App\Support\Authz;

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
        private MembershipDirectory $memberships,
    ) {}

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
}
