<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use App\Features\Events\Api\EventMemberInvitationEligibility;
use App\Features\Membership\Api\MemberInvitationAccess;
use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Shared\Auth\AccountActor;

final readonly class MemberInvitationGate implements MemberInvitationAccess
{
    public function __construct(
        private MemberPermissions $memberPermissions,
        private EventMemberInvitationEligibility $eventInvitations,
        private OrganizationAccess $organizations,
    ) {}

    public function canInvite(AccountActor $actor, MemberSubjectType $subjectType, string $subjectId): bool
    {
        if ($subjectId === '') {
            return false;
        }

        return match ($subjectType) {
            MemberSubjectType::Institution => $this->memberPermissions->canSubject($actor, 'institution.manage-members', $subjectType, $subjectId),
            MemberSubjectType::Person => $this->memberPermissions->canSubject($actor, 'person.manage-members', $subjectType, $subjectId),
            MemberSubjectType::Reference => $this->memberPermissions->canSubject($actor, 'reference.manage-members', $subjectType, $subjectId),
            MemberSubjectType::Event => $this->eventInvitations->canInvite($actor, $subjectId),
            MemberSubjectType::Organization => $this->organizations->can($actor->actorId(), $subjectId, 'organization.manage-members'),
        };
    }
}
