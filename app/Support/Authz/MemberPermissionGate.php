<?php

namespace App\Support\Authz;

use AIArmada\Organizations\Models\Organization;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

final readonly class MemberPermissionGate
{
    private const array ROLE_WEIGHT = [
        'owner' => 100,
        'admin' => 80,
        'editor' => 50,
        'viewer' => 10,
    ];

    private const array PERMISSION_THRESHOLD = [
        'view' => 10,
        'create' => 10,
        'update' => 80,
        'delete' => 100,
        'transfer-ownership' => 100,
        'manage-members' => 80,
        'approve' => 80,
        'manage-donation-channels' => 80,
        'view-registrations' => 10,
        'export-registrations' => 80,
    ];

    private const array PERSON_SCOPE_PERMISSION_THRESHOLD = [
        'delete' => 80,
    ];

    public function canInstitution(User $user, string $permission, Institution $institution): bool
    {
        return $this->memberCan($institution, $user, $permission);
    }

    public function canPerson(User $user, string $permission, Person $person): bool
    {
        return $this->memberCan($person, $user, $permission, $this->eligiblePersonScopeRoles($permission));
    }

    public function canOrganization(User $user, string $permission, Organization $organization): bool
    {
        return $this->memberCan($organization, $user, $permission);
    }

    public function canEvent(User $user, string $permission, Event $event): bool
    {
        return $this->memberCan($event, $user, $permission);
    }

    public function canEventThroughPerson(User $user, string $permission, Event $event): bool
    {
        $roles = $this->eligiblePersonScopeRoles($permission);
        $membershipTable = (new Person)->membersTable();

        if ($roles === []) {
            return false;
        }

        return $event->persons()
            ->whereHas('members', function (Builder $memberQuery) use ($membershipTable, $roles, $user): void {
                $memberQuery
                    ->whereKey($user->getKey())
                    ->whereIn("{$membershipTable}.role", $roles);
            })
            ->exists();
    }

    public function canReference(User $user, string $permission, Reference $reference): bool
    {
        return $this->memberCan($reference, $user, $permission);
    }

    public function hasAnyInstitutionPermission(User $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user->institutions(), $permission);
    }

    public function hasAnyEventPermission(User $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user->memberEvents(), $permission);
    }

    public function hasAnyPersonPermission(User $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission(
            $user->persons(),
            $permission,
            $this->eligiblePersonScopeRoles($permission),
        );
    }

    public function hasAnyOrganizationPermission(User $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user->organizations(), $permission);
    }

    public function hasAnyReferencePermission(User $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user->references(), $permission);
    }

    /**
     * @return Collection<int, User>
     */
    public function institutionMembersWithPermission(Institution $institution, string $permission): Collection
    {
        return $this->membersWithPermission($institution, $permission);
    }

    /**
     * @return Collection<int, User>
     */
    public function personMembersWithPermission(Person $person, string $permission): Collection
    {
        return $this->membersWithPermission($person, $permission, $this->eligiblePersonScopeRoles($permission));
    }

    /**
     * @return Collection<int, User>
     */
    public function eventMembersWithPermission(Event $event, string $permission): Collection
    {
        return $this->membersWithPermission($event, $permission);
    }

    /**
     * @param  list<string>|null  $roles
     */
    private function memberCan(Model $subject, User $user, string $permission, ?array $roles = null): bool
    {
        if (! method_exists($subject, 'members')) {
            return false;
        }

        $roles ??= $this->eligibleRoles($permission);

        return $roles !== []
            && $subject->members()->whereKey($user->getKey())->wherePivotIn('role', $roles)->exists();
    }

    /**
     * @return Collection<int, User>
     */
    /**
     * @param  list<string>|null  $roles
     * @return Collection<int, User>
     */
    private function membersWithPermission(Model $subject, string $permission, ?array $roles = null): Collection
    {
        if (! method_exists($subject, 'members')) {
            return collect();
        }

        $roles ??= $this->eligibleRoles($permission);

        if ($roles === []) {
            return collect();
        }

        /** @var Collection<int, User> $members */
        $members = $subject->members()->wherePivotIn('role', $roles)->get();

        return $members;
    }

    /**
     * @template TRelatedModel of Model
     * @template TPivot of Pivot
     *
     * @param  BelongsToMany<TRelatedModel, User, TPivot, 'pivot'>  $memberships
     * @param  list<string>|null  $roles
     */
    private function hasAnyMembershipWithPermission(BelongsToMany $memberships, string $permission, ?array $roles = null): bool
    {
        $roles ??= $this->eligibleRoles($permission);

        return $roles !== [] && $memberships->wherePivotIn('role', $roles)->exists();
    }

    /**
     * @return list<string>
     */
    private function eligibleRoles(string $permission): array
    {
        $threshold = $this->permissionThreshold($permission);

        return $this->rolesAtOrAbove($threshold);
    }

    /**
     * Person membership also grants the linked-resource permissions that the
     * product treats as owner-level. Other membership subjects retain their
     * normal permission thresholds.
     *
     * @return list<string>
     */
    private function eligiblePersonScopeRoles(string $permission): array
    {
        $shortPermission = $this->shortPermissionName($permission);
        $threshold = self::PERSON_SCOPE_PERMISSION_THRESHOLD[$shortPermission]
            ?? $this->permissionThreshold($permission);

        return $this->rolesAtOrAbove($threshold);
    }

    /**
     * @return list<string>
     */
    private function rolesAtOrAbove(?int $threshold): array
    {
        if ($threshold === null) {
            return [];
        }

        return array_keys(array_filter(
            self::ROLE_WEIGHT,
            static fn (int $weight): bool => $weight >= $threshold,
        ));
    }

    private function permissionThreshold(string $permission): ?int
    {
        return self::PERMISSION_THRESHOLD[$this->shortPermissionName($permission)] ?? null;
    }

    private function shortPermissionName(string $permission): string
    {
        $parts = explode('.', $permission);

        return end($parts);
    }
}
