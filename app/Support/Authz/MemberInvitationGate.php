<?php

declare(strict_types=1);

namespace App\Support\Authz;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;

final readonly class MemberInvitationGate
{
    public function __construct(
        private MemberPermissionGate $memberPermissionGate,
    ) {}

    public function canInvite(User $user, Institution|Person|Event|Reference $subject): bool
    {
        return match (true) {
            $subject instanceof Institution => $this->memberPermissionGate->canInstitution($user, 'institution.manage-members', $subject),
            $subject instanceof Person => $this->memberPermissionGate->canPerson($user, 'person.manage-members', $subject),
            $subject instanceof Event => $subject->userHasScopedEventPermission($user, 'event.manage-members'),
            $subject instanceof Reference => $this->memberPermissionGate->canReference($user, 'reference.manage-members', $subject),
        };
    }
}
