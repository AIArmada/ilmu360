<?php

namespace App\Features\Membership\Services;

use AIArmada\Organizations\Models\Organization;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipPermissionEvaluator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class MemberPermissionGate implements MemberPermissions, MembershipPermissionEvaluator
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
        'manage-attendance' => 10,
        'manage-admissions' => 80,
        'export-registrations' => 80,
    ];

    private const array PERSON_SCOPE_PERMISSION_THRESHOLD = [
        'delete' => 80,
    ];

    public function __construct(
        private MembershipDirectory $memberships,
    ) {}

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

        $personIds = $event->persons()
            ->pluck((new Person)->getQualifiedKeyName())
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        if ($personIds === []) {
            return false;
        }

        return array_any(
            $this->memberships->membershipsFor($user->actorId(), MemberSubjectType::Person),
            static fn ($membership): bool => in_array($membership->subjectId, $personIds, true)
                && in_array($membership->role, $roles, true),
        );
    }

    public function canReference(AccountActor $user, string $permission, Reference $reference): bool
    {
        return $this->memberCan(MemberSubjectType::Reference, (string) $reference->getKey(), $user, $permission);
    }

    public function canOnSubject(
        string $accountId,
        string $permission,
        MemberSubjectType $subjectType,
        string $subjectId,
    ): bool {
        $account = User::query()->find($accountId);
        $subject = $this->subject($subjectType, $subjectId);

        if (! $account instanceof User || ! $subject instanceof Model) {
            return false;
        }

        return match (true) {
            $subject instanceof Institution => $this->canInstitution($account, $permission, $subject),
            $subject instanceof Person => $this->canPerson($account, $permission, $subject),
            $subject instanceof Event => $this->canEvent($account, $permission, $subject),
            $subject instanceof Reference => $this->canReference($account, $permission, $subject),
            default => $this->canOrganization($account, $permission, $subject),
        };
    }

    public function canThroughPersonSubject(string $accountId, string $permission, string $eventId): bool
    {
        $account = User::query()->find($accountId);
        $event = Event::query()->find($eventId);

        return $account instanceof User
            && $event instanceof Event
            && $this->canEventThroughPerson($account, $permission, $event);
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
        $subject = $this->subject($subjectType, $subjectId);

        if (! $subject instanceof Model) {
            return [];
        }

        return match (true) {
            $subject instanceof Institution => $this->accountIds(
                $this->institutionMembersWithPermission($subject, $permission),
            ),
            $subject instanceof Person => $this->accountIds(
                $this->personMembersWithPermission($subject, $permission),
            ),
            $subject instanceof Event => $this->accountIds(
                $this->eventMembersWithPermission($subject, $permission),
            ),
            $subject instanceof Reference => $this->permittedAccountIds(
                $subject->members()->get(),
                fn (User $member): bool => $this->canReference($member, $permission, $subject),
            ),
            default => $this->permittedAccountIds(
                $subject->members()->get(),
                fn (User $member): bool => $this->canOrganization($member, $permission, $subject),
            ),
        };
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

    private function subject(
        MemberSubjectType $subjectType,
        string $subjectId,
    ): Institution|Person|Event|Reference|Organization|null {
        return match ($subjectType) {
            MemberSubjectType::Institution => Institution::query()->find($subjectId),
            MemberSubjectType::Person => Person::query()->find($subjectId),
            MemberSubjectType::Event => Event::query()->find($subjectId),
            MemberSubjectType::Reference => Reference::query()->find($subjectId),
            MemberSubjectType::Organization => Organization::query()->find($subjectId),
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
     * @param  Collection<int, Model>  $members
     * @param  callable(User): bool  $allows
     * @return list<string>
     */
    private function permittedAccountIds(Collection $members, callable $allows): array
    {
        $accountIds = [];

        foreach ($members as $member) {
            if ($member instanceof User && $allows($member)) {
                $accountIds[] = (string) $member->getKey();
            }
        }

        return $accountIds;
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
            && $this->memberships->hasAnyRole($user->actorId(), $subjectType, $subjectId, $roles);
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

        foreach ($this->memberships->members($subjectType, $subjectId) as $membership) {
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
            && $this->memberships->hasAnyMembershipRole($user->actorId(), $subjectType, $roles);
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
