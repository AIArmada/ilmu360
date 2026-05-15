<?php

namespace App\Support\Authz;

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\AuthzScope;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class MemberPermissionGate
{
    public function __construct(
        private MemberRoleScopes $memberRoleScopes,
    ) {}

    public function canInstitution(User $user, string $permission, Institution $institution): bool
    {
        return $this->isInstitutionMember($user, $institution)
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->institution());
    }

    public function canSpeaker(User $user, string $permission, Speaker $speaker): bool
    {
        return $this->isSpeakerMember($user, $speaker)
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->speaker());
    }

    public function canEvent(User $user, string $permission, Event $event): bool
    {
        return $this->isEventMember($user, $event)
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->event());
    }

    public function canReference(User $user, string $permission, Reference $reference): bool
    {
        return $this->isReferenceMember($user, $reference)
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->reference());
    }

    public function hasAnyInstitutionPermission(User $user, string $permission): bool
    {
        return $user->institutions()->exists()
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->institution());
    }

    public function hasAnyEventPermission(User $user, string $permission): bool
    {
        return $user->memberEvents()->exists()
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->event());
    }

    public function hasAnySpeakerPermission(User $user, string $permission): bool
    {
        return $user->speakers()->exists()
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->speaker());
    }

    public function hasAnyReferencePermission(User $user, string $permission): bool
    {
        return $user->references()->exists()
            && Authz::userCanInScope($user, $permission, $this->memberRoleScopes->reference());
    }

    /**
     * @return Collection<int, User>
     */
    public function institutionMembersWithPermission(Institution $institution, string $permission): Collection
    {
        /** @var Collection<int, User> $members */
        $members = $institution->members()->get();

        return $members
            ->filter(fn (User $member): bool => $this->canInstitution($member, $permission, $institution))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function speakerMembersWithPermission(Speaker $speaker, string $permission): Collection
    {
        /** @var Collection<int, User> $members */
        $members = $speaker->members()->get();

        return $members
            ->filter(fn (User $member): bool => $this->canSpeaker($member, $permission, $speaker))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function eventMembersWithPermission(Event $event, string $permission): Collection
    {
        /** @var Collection<int, User> $members */
        $members = $event->members()->get();

        return $members
            ->filter(fn (User $member): bool => $this->canEvent($member, $permission, $event))
            ->values();
    }

    public function institutionScope(): AuthzScope
    {
        return $this->memberRoleScopes->institution();
    }

    public function speakerScope(): AuthzScope
    {
        return $this->memberRoleScopes->speaker();
    }

    public function eventScope(): AuthzScope
    {
        return $this->memberRoleScopes->event();
    }

    public function referenceScope(): AuthzScope
    {
        return $this->memberRoleScopes->reference();
    }

    private function isInstitutionMember(User $user, Institution $institution): bool
    {
        return $institution->members()->whereKey($user->getKey())->exists();
    }

    private function isSpeakerMember(User $user, Speaker $speaker): bool
    {
        return $speaker->members()->whereKey($user->getKey())->exists();
    }

    private function isEventMember(User $user, Event $event): bool
    {
        return $event->members()->whereKey($user->getKey())->exists();
    }

    private function isReferenceMember(User $user, Reference $reference): bool
    {
        return $reference->members()->whereKey($user->getKey())->exists();
    }
}
