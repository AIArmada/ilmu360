<?php

declare(strict_types=1);

namespace App\Support\Authz;

use AIArmada\Organizations\Contracts\OrganizationAuthorization;
use AIArmada\Organizations\Models\Organization;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class MemberInvitationGate
{
    public function __construct(
        private MemberPermissionGate $memberPermissionGate,
        private OrganizationAuthorization $organizationAuthorization,
    ) {}

    public function canInvite(User $user, Institution|Person|Event|Reference|Organization $subject): bool
    {
        return match (true) {
            $subject instanceof Institution => $this->memberPermissionGate->canInstitution($user, 'institution.manage-members', $subject),
            $subject instanceof Person => $this->memberPermissionGate->canPerson($user, 'person.manage-members', $subject),
            $subject instanceof Event => $subject->userHasScopedEventPermission($user, 'event.manage-members'),
            $subject instanceof Reference => $this->memberPermissionGate->canReference($user, 'reference.manage-members', $subject),
            $subject instanceof Organization => $this->canInviteToOrganization($user, $subject),
        };
    }

    private function canInviteToOrganization(User $user, Organization $organization): bool
    {
        try {
            $this->organizationAuthorization->authorize($user, $organization, 'organization.manage-members');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
