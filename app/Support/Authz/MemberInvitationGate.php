<?php

declare(strict_types=1);

namespace App\Support\Authz;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;

final readonly class MemberInvitationGate
{
    public function __construct(
        private MemberPermissionGate $memberPermissionGate,
    ) {}

    public function canInvite(User $user, Institution|Speaker|Event|Reference $subject): bool
    {
        return match (true) {
            $subject instanceof Institution => $this->memberPermissionGate->canInstitution($user, 'institution.manage-members', $subject),
            $subject instanceof Speaker => $this->memberPermissionGate->canSpeaker($user, 'speaker.manage-members', $subject),
            $subject instanceof Event => $subject->userHasScopedEventPermission($user, 'event.manage-members'),
            $subject instanceof Reference => $this->memberPermissionGate->canReference($user, 'reference.manage-members', $subject),
        };
    }
}
