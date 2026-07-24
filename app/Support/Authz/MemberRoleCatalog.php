<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\FilamentAuthz\Facades\Authz;
use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final readonly class MemberRoleCatalog
{
    public function __construct(
        private MemberRoleScopes $scopes,
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
    public function roleNamesFor(User $user, MemberSubjectType $type): array
    {
        $modelClass = $type->modelClass();
        $subject = $this->subjectForUser($user, $modelClass);

        if ($subject === null) {
            return [];
        }

        $previousTeamId = getPermissionsTeamId();

        try {
            setPermissionsTeamId($subject->getKey());

            /** @var Collection<int, Role> $roles */
            $roles = $user->roles;

            return $roles
                ->map(fn (Role $role): string => $role->name)
                ->values()
                ->all();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    private function subjectForUser(User $user, string $modelClass): mixed
    {
        $relation = match ($modelClass) {
            Institution::class => 'institutions',
            Person::class => 'persons',
            Event::class => 'memberEvents',
            Reference::class => 'references',
            default => null,
        };

        if ($relation === null || ! method_exists($user, $relation)) {
            return null;
        }

        return $user->{$relation}()->first();
    }

    private function scopeForType(MemberSubjectType $type): mixed
    {
        return match ($type) {
            MemberSubjectType::Institution => $this->scopes->institution(),
            MemberSubjectType::Person => $this->scopes->person(),
            MemberSubjectType::Event => $this->scopes->event(),
            MemberSubjectType::Reference => $this->scopes->reference(),
        };
    }
}
