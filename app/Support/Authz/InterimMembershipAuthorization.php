<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\Organizations\Models\Organization;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Shared\Membership\MembershipAuthorization;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Interim membership authorization adapter over the current permission gate.
 *
 * MemberPermissionGate remains the sole runtime authorization path for existing
 * consumers until Membership is extracted. This adapter only gives future
 * shared/feature callers a stable, scalar boundary.
 */
final readonly class InterimMembershipAuthorization implements MembershipAuthorization
{
    public function __construct(
        private MemberPermissionGate $gate,
    ) {}

    public function can(
        string $accountId,
        string $permission,
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): bool {
        $account = User::query()->find($accountId);
        $subject = $this->subject($subjectType, $subjectId);

        if (! $account instanceof User || ! $subject instanceof Model) {
            return false;
        }

        return match (true) {
            $subject instanceof Institution => $this->gate->canInstitution($account, $permission, $subject),
            $subject instanceof Person => $this->gate->canPerson($account, $permission, $subject),
            $subject instanceof Event => $this->gate->canEvent($account, $permission, $subject),
            $subject instanceof Reference => $this->gate->canReference($account, $permission, $subject),
            default => $this->gate->canOrganization($account, $permission, $subject),
        };
    }

    public function canThroughPerson(string $accountId, string $permission, string $eventId): bool
    {
        $account = User::query()->find($accountId);
        $event = Event::query()->find($eventId);

        return $account instanceof User
            && $event instanceof Event
            && $this->gate->canEventThroughPerson($account, $permission, $event);
    }

    public function hasAny(
        string $accountId,
        string $permission,
        MembershipSubjectType $subjectType,
    ): bool {
        $account = User::query()->find($accountId);

        if (! $account instanceof User) {
            return false;
        }

        return match ($subjectType) {
            MembershipSubjectType::Institution => $this->gate->hasAnyInstitutionPermission($account, $permission),
            MembershipSubjectType::Person => $this->gate->hasAnyPersonPermission($account, $permission),
            MembershipSubjectType::Event => $this->gate->hasAnyEventPermission($account, $permission),
            MembershipSubjectType::Reference => $this->gate->hasAnyReferencePermission($account, $permission),
            MembershipSubjectType::Organization => $this->gate->hasAnyOrganizationPermission($account, $permission),
        };
    }

    public function membersWithPermission(
        MembershipSubjectType $subjectType,
        string $subjectId,
        string $permission,
    ): array {
        $subject = $this->subject($subjectType, $subjectId);

        if (! $subject instanceof Model) {
            return [];
        }

        return match (true) {
            $subject instanceof Institution => $this->accountIds(
                $this->gate->institutionMembersWithPermission($subject, $permission),
            ),
            $subject instanceof Person => $this->accountIds(
                $this->gate->personMembersWithPermission($subject, $permission),
            ),
            $subject instanceof Event => $this->accountIds(
                $this->gate->eventMembersWithPermission($subject, $permission),
            ),
            $subject instanceof Reference => $this->permittedAccountIds(
                $subject->members()->get(),
                fn (User $member): bool => $this->gate->canReference($member, $permission, $subject),
            ),
            default => $this->permittedAccountIds(
                $subject->members()->get(),
                fn (User $member): bool => $this->gate->canOrganization($member, $permission, $subject),
            ),
        };
    }

    private function subject(
        MembershipSubjectType $subjectType,
        string $subjectId,
    ): Institution|Person|Event|Reference|Organization|null {
        return match ($subjectType) {
            MembershipSubjectType::Institution => Institution::query()->find($subjectId),
            MembershipSubjectType::Person => Person::query()->find($subjectId),
            MembershipSubjectType::Event => Event::query()->find($subjectId),
            MembershipSubjectType::Reference => Reference::query()->find($subjectId),
            MembershipSubjectType::Organization => Organization::query()->find($subjectId),
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
}
