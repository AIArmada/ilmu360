<?php

namespace App\Features\Membership\Services;

use AIArmada\Organizations\Models\Organization;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Membership\Contracts\MembershipPermissionEvaluator;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Models\Person;
use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Shared\Auth\AccountActor;
use Illuminate\Database\Eloquent\Collection;

final readonly class MemberPermissionGate implements MemberPermissions, MembershipPermissionEvaluator
{
    /**
     * Membership, institution, person, reference, event and organization reads
     * are resolved at call time. Several of those owners depend on membership
     * authorization themselves; constructor injection would create a container
     * cycle (PersonLookup -> PersonSearchService -> PersonWorkflow ->
     * CreatePersonAction -> MembershipCommands -> ... -> this gate).
     */
    private function memberships(): MembershipDirectory
    {
        return app(MembershipDirectory::class);
    }

    private function institutions(): InstitutionLookup
    {
        return app(InstitutionLookup::class);
    }

    private function persons(): PersonLookup
    {
        return app(PersonLookup::class);
    }

    private function references(): ReferenceLookup
    {
        return app(ReferenceLookup::class);
    }

    private function eventInvolvements(): EventAuthoring
    {
        return app(EventAuthoring::class);
    }

    private function organizations(): OrganizationAccess
    {
        return app(OrganizationAccess::class);
    }

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
        'manage-attendance' => 10,
        'manage-admissions' => 80,
        'export-registrations' => 80,
    ];

    private const array PERSON_SCOPE_PERMISSION_THRESHOLD = [
        'delete' => 80,
    ];

    public function canInstitution(AccountActor $user, string $permission, Institution $institution): bool
    {
        return $this->memberCan(MemberSubjectType::Institution, (string) $institution->getKey(), $user, $permission);
    }

    public function canPerson(AccountActor $user, string $permission, Person $person): bool
    {
        return $this->memberCan(
            MemberSubjectType::Person,
            (string) $person->getKey(),
            $user,
            $permission,
            $this->eligiblePersonScopeRoles($permission),
        );
    }

    public function canOrganization(AccountActor $user, string $permission, Organization $organization): bool
    {
        return $this->memberCan(MemberSubjectType::Organization, (string) $organization->getKey(), $user, $permission);
    }

    public function canEvent(AccountActor $user, string $permission, Event $event): bool
    {
        return $this->memberCan(MemberSubjectType::Event, (string) $event->getKey(), $user, $permission);
    }

    public function canEventThroughPerson(AccountActor $user, string $permission, Event $event): bool
    {
        $roles = $this->eligiblePersonScopeRoles($permission);

        if ($roles === []) {
            return false;
        }

        $personIds = $this->eventInvolvements()->eventPersonIds((string) $event->getKey());

        if ($personIds === []) {
            return false;
        }

        return array_any(
            $this->memberships()->membershipsFor($user->actorId(), MemberSubjectType::Person),
            static fn ($membership): bool => in_array($membership->subjectId, $personIds, true)
                && in_array($membership->role, $roles, true),
        );
    }

    public function canReference(AccountActor $user, string $permission, Reference $reference): bool
    {
        return $this->memberCan(MemberSubjectType::Reference, (string) $reference->getKey(), $user, $permission);
    }

    public function canSubject(
        AccountActor $user,
        string $permission,
        MemberSubjectType $subjectType,
        string $subjectId,
    ): bool {
        return $this->memberCan(
            $subjectType,
            $subjectId,
            $user,
            $permission,
            $subjectType === MemberSubjectType::Person
                ? $this->eligiblePersonScopeRoles($permission)
                : null,
        );
    }

    /**
     * @return list<string>
     */
    public function subjectMembersWithPermission(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array {
        $members = $subjectType === MemberSubjectType::Person
            ? $this->membersWithPermission($subjectType, $subjectId, $permission, $this->eligiblePersonScopeRoles($permission))
            : $this->membersWithPermission($subjectType, $subjectId, $permission);

        return $members
            ->map(static fn (User $member): string => (string) $member->getKey())
            ->values()
            ->all();
    }

    public function canOnSubject(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
        string $subjectId,
    ): bool {
        $account = User::query()->find($accountId);

        if (! $account instanceof User || ! $this->subjectExists($subjectType, $subjectId)) {
            return false;
        }

        return $this->memberCan(
            $subjectType,
            $subjectId,
            $account,
            $permission,
            $subjectType === MemberSubjectType::Person
                ? $this->eligiblePersonScopeRoles($permission)
                : null,
        );
    }

    public function canThroughPersonSubject(string $accountId, string $permission, string $eventId): bool
    {
        $account = User::query()->find($accountId);
        $roles = $this->eligiblePersonScopeRoles($permission);

        if (! $account instanceof User || $roles === []) {
            return false;
        }

        $personIds = $this->eventInvolvements()->eventPersonIds($eventId);

        if ($personIds === []) {
            return false;
        }

        return array_any(
            $this->memberships()->membershipsFor($account->actorId(), MemberSubjectType::Person),
            static fn ($membership): bool => in_array($membership->subjectId, $personIds, true)
                && in_array($membership->role, $roles, true),
        );
    }

    public function hasAnyOnSubjectType(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
    ): bool {
        $account = User::query()->find($accountId);

        if (! $account instanceof User) {
            return false;
        }

        return match ($subjectType) {
            MemberSubjectType::Institution => $this->hasAnyInstitutionPermission($account, $permission),
            MemberSubjectType::Person => $this->hasAnyPersonPermission($account, $permission),
            MemberSubjectType::Event => $this->hasAnyEventPermission($account, $permission),
            MemberSubjectType::Reference => $this->hasAnyReferencePermission($account, $permission),
            MemberSubjectType::Organization => $this->hasAnyOrganizationPermission($account, $permission),
        };
    }

    public function memberAccountIdsWithPermission(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array {
        if (! $this->subjectExists($subjectType, $subjectId)) {
            return [];
        }

        return $this->accountIds(
            $this->membersWithPermission(
                $subjectType,
                $subjectId,
                $permission,
                $subjectType === MemberSubjectType::Person
                    ? $this->eligiblePersonScopeRoles($permission)
                    : null,
            ),
        );
    }

    public function hasAnyInstitutionPermission(AccountActor $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user, MemberSubjectType::Institution, $permission);
    }

    public function hasAnyEventPermission(AccountActor $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user, MemberSubjectType::Event, $permission);
    }

    public function hasAnyPersonPermission(AccountActor $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission(
            $user,
            MemberSubjectType::Person,
            $permission,
            $this->eligiblePersonScopeRoles($permission),
        );
    }

    public function hasAnyOrganizationPermission(AccountActor $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user, MemberSubjectType::Organization, $permission);
    }

    public function hasAnyReferencePermission(AccountActor $user, string $permission): bool
    {
        return $this->hasAnyMembershipWithPermission($user, MemberSubjectType::Reference, $permission);
    }

    /**
     * @return Collection<int, User>
     */
    public function institutionMembersWithPermission(Institution $institution, string $permission): Collection
    {
        return $this->membersWithPermission(MemberSubjectType::Institution, (string) $institution->getKey(), $permission);
    }

    /**
     * @return Collection<int, User>
     */
    public function personMembersWithPermission(Person $person, string $permission): Collection
    {
        return $this->membersWithPermission(
            MemberSubjectType::Person,
            (string) $person->getKey(),
            $permission,
            $this->eligiblePersonScopeRoles($permission),
        );
    }

    /**
     * @return Collection<int, User>
     */
    public function eventMembersWithPermission(Event $event, string $permission): Collection
    {
        return $this->membersWithPermission(MemberSubjectType::Event, (string) $event->getKey(), $permission);
    }

    private function subjectExists(MemberSubjectType $subjectType, string $subjectId): bool
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => $this->institutions()->exists($subjectId),
            MemberSubjectType::Person => $this->persons()->exists($subjectId),
            MemberSubjectType::Event => $this->eventInvolvements()->eventExists($subjectId),
            MemberSubjectType::Reference => $this->references()->exists($subjectId),
            MemberSubjectType::Organization => $this->organizations()->find($subjectId) instanceof \App\Features\Organizations\Api\OrganizationSummary,
        };
    }

    /**
     * @param  Collection<int, User>  $members
     * @return list<string>
     */
    private function accountIds(Collection $members): array
    {
        return $members
            ->map(static fn (User $member): string => (string) $member->getKey())
            ->values()
            ->all();
    }

    /**
     * @param  list<string>|null  $roles
     */
    private function memberCan(
        MemberSubjectType $subjectType,
        string $subjectId,
        AccountActor $user,
        string $permission,
        ?array $roles = null,
    ): bool {
        $roles ??= $this->eligibleRoles($permission);

        return $roles !== []
            && $subjectId !== ''
            && $this->memberships()->hasAnyRole($user->actorId(), $subjectType, $subjectId, $roles);
    }

    /**
     * @param  list<string>|null  $roles
     * @return Collection<int, User>
     */
    private function membersWithPermission(
        MemberSubjectType $subjectType,
        string $subjectId,
        string $permission,
        ?array $roles = null,
    ): Collection {
        $roles ??= $this->eligibleRoles($permission);

        if ($roles === [] || $subjectId === '') {
            return new Collection;
        }

        $accountIds = [];

        foreach ($this->memberships()->members($subjectType, $subjectId) as $membership) {
            if (in_array($membership->role, $roles, true)) {
                $accountIds[] = $membership->accountId;
            }
        }

        if ($accountIds === []) {
            return new Collection;
        }

        /** @var Collection<int, User> $members */
        $members = User::query()->whereKey($accountIds)->get();

        return $members;
    }

    /**
     * @param  list<string>|null  $roles
     */
    private function hasAnyMembershipWithPermission(
        AccountActor $user,
        MemberSubjectType $subjectType,
        string $permission,
        ?array $roles = null,
    ): bool {
        $roles ??= $this->eligibleRoles($permission);

        return $roles !== []
            && $this->memberships()->hasAnyMembershipRole($user->actorId(), $subjectType, $roles);
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
