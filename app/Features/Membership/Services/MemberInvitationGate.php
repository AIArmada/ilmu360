<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use AIArmada\Organizations\Contracts\OrganizationAuthorization;
use AIArmada\Organizations\Models\Organization;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Shared\Auth\AccountActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

final readonly class MemberInvitationGate
{
    public function __construct(
        private MemberPermissionGate $memberPermissionGate,
        private OrganizationAuthorization $organizationAuthorization,
    ) {}

    public function canInvite(AccountActor $user, Institution|Person|Event|Reference|Organization $subject): bool
    {
        return match (true) {
            $subject instanceof Institution => $this->memberPermissionGate->canInstitution($user, 'institution.manage-members', $subject),
            $subject instanceof Person => $this->memberPermissionGate->canPerson($user, 'person.manage-members', $subject),
            $subject instanceof Event => $subject->userHasScopedEventPermission($user, 'event.manage-members'),
            $subject instanceof Reference => $this->memberPermissionGate->canReference($user, 'reference.manage-members', $subject),
            $subject instanceof Organization => $user instanceof Model && $this->canInviteToOrganization($user, $subject),
        };
    }

    private function canInviteToOrganization(Model $user, Organization $organization): bool
    {
        try {
            $this->organizationAuthorization->authorize($user, $organization, 'organization.manage-members');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
