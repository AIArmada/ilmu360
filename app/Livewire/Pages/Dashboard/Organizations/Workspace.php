<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Organizations;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Models\Organization;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipInvitationDetail;
use App\Features\Membership\Api\MembershipInvitations;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationCommands;
use App\Models\Event;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Organization workspace')]
final class Workspace extends Component
{
    use InteractsWithToasts;

    public string $organizationId = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'viewer';

    public ?string $editingMemberId = null;

    public string $editingRole = 'viewer';

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Organization $organization): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        abort_unless(
            app(OrganizationAccess::class)->can((string) $user->getKey(), (string) $organization->getKey(), 'organization.view'),
            403,
        );

        $this->organizationId = (string) $organization->getKey();
    }

    public function invite(
        MembershipInvitations $membershipInvitations,
    ): void {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $this->authorizeManageMembers($organization);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:255'],
            'inviteRole' => ['required', Rule::in($this->manageableRoles())],
        ]);

        $email = mb_strtolower(trim((string) $validated['inviteEmail']));

        if ($organization->members()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages([
                'inviteEmail' => __('This person is already a member of the organization.'),
            ]);
        }

        $membershipInvitations->invite(
            MembershipSubjectType::Organization,
            (string) $organization->getKey(),
            $email,
            (string) $validated['inviteRole'],
            (string) $user->getKey(),
        );

        $this->reset('inviteEmail');
        $this->successToast(__('Invitation sent successfully.'));
    }

    public function startEditingMember(string $memberId): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeManageMembers($organization);

        $member = $this->memberOrFail($organization, $memberId);
        $role = MemberRole::fromSpatieRoleName((string) data_get($member->getRelationValue('pivot'), 'role'));

        if ($role === MemberRole::Owner) {
            $this->errorToast(__('Transfer ownership before changing the owner role.'));

            return;
        }

        $this->editingMemberId = $memberId;
        $this->editingRole = $role instanceof MemberRole ? $role->value : MemberRole::Viewer->value;
    }

    public function cancelEditingMember(): void
    {
        $this->reset(['editingMemberId', 'editingRole']);
        $this->editingRole = MemberRole::Viewer->value;
    }

    public function saveMemberRole(MembershipCommands $membershipCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeManageMembers($organization);

        if ($this->editingMemberId === null) {
            abort(404);
        }

        $validated = $this->validate([
            'editingRole' => ['required', Rule::in($this->manageableRoles())],
        ]);
        $member = $this->memberOrFail($organization, $this->editingMemberId);

        OwnerContext::withOwner($organization, function () use ($membershipCommands, $member, $organization, $validated): void {
            $membershipCommands->changeMemberRole(
                MembershipSubjectType::Organization,
                (string) $organization->getKey(),
                (string) $member->getKey(),
                (string) $validated['editingRole'],
            );
        });

        $this->cancelEditingMember();
        $this->successToast(__('Member role updated.'));
    }

    public function removeMember(string $memberId, MembershipCommands $membershipCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeManageMembers($organization);
        $member = $this->memberOrFail($organization, $memberId);

        try {
            OwnerContext::withOwner($organization, function () use ($membershipCommands, $member, $organization): void {
                $membershipCommands->removeMember(
                    MembershipSubjectType::Organization,
                    (string) $organization->getKey(),
                    (string) $member->getKey(),
                );
            });
        } catch (Throwable $throwable) {
            report($throwable);
            $this->errorToast(__('This member cannot be removed. Transfer ownership first if needed.'));

            return;
        }

        $this->successToast(__('Member removed successfully.'));
    }

    public function transferOwnership(string $memberId, OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $member = $this->memberOrFail($organization, $memberId);

        OwnerContext::withOwner($organization, function () use ($member, $organization, $organizationCommands, $user): void {
            $organizationCommands->transferOwnership(
                (string) $organization->getKey(),
                (string) $user->getKey(),
                (string) $member->getKey(),
            );
        });

        $this->successToast(__('Organization ownership transferred.'));
    }

    public function revokeInvitation(
        string $invitationId,
        MembershipInvitations $membershipInvitations,
    ): void {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $this->authorizeManageMembers($organization);

        $invitation = $membershipInvitations->find($invitationId);

        abort_unless(
            $invitation !== null
            && $invitation->subjectType === MembershipSubjectType::Organization
            && $invitation->subjectId === (string) $organization->getKey(),
            404,
        );

        $membershipInvitations->revoke($invitationId, (string) $user->getKey());

        $this->successToast(__('Invitation revoked.'));
    }

    public function makePublic(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-visibility');
        $organizationCommands->makePublic((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization is now public.'));
    }

    public function makePrivate(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-visibility');
        $organizationCommands->makePrivate((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization is now private.'));
    }

    public function suspend(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $organizationCommands->suspend((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization suspended.'));
    }

    public function restore(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $organizationCommands->restore((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization restored.'));
    }

    public function archive(OrganizationCommands $organizationCommands): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $organizationCommands->archive((string) $organization->getKey(), (string) $this->currentUser()->getKey());
        $this->successToast(__('Organization archived.'));
    }

    /** @return Collection<int, User> */
    public function members(): Collection
    {
        $organization = $this->selectedOrganization();

        return OwnerContext::withOwner($organization, fn (): Collection => $organization->members()->orderBy('name')->get());
    }

    /** @return list<MembershipInvitationDetail> */
    public function invitations(): array
    {
        return app(MembershipInvitations::class)->recentForSubject(
            MembershipSubjectType::Organization,
            (string) $this->selectedOrganization()->getKey(),
            20,
        );
    }

    /** @return Collection<int, Event> */
    public function events(): Collection
    {
        $organization = $this->selectedOrganization();

        return OwnerContext::withOwner($organization, fn (): Collection => Event::query()
            ->where('owner_type', $organization->getMorphClass())
            ->where('owner_id', $organization->getKey())
            ->latest('created_at')
            ->limit(20)
            ->get());
    }

    /** @return array<string, string> */
    public function roleOptions(): array
    {
        return collect($this->manageableRoles())
            ->mapWithKeys(fn (string $role): array => [$role => MemberRole::from($role)->label()])
            ->all();
    }

    public function canManageMembers(): bool
    {
        try {
            $this->authorizeManageMembers($this->selectedOrganization());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function canManageOrganization(): bool
    {
        try {
            $this->selectedOrganizationAuthorization('organization.change-status');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function render(): View
    {
        $organization = $this->selectedOrganization();
        $events = $this->events();
        $publicEventIds = OwnerContext::withOwner($organization, fn (): array => $events
            ->filter(fn (Event $event): bool => $event->isPubliclyReachable())
            ->mapWithKeys(fn (Event $event): array => [(string) $event->getKey() => true])
            ->all());
        $eventEditUrls = [];
        $eventScheduleUrls = [];
        $eventOfflineAdmissionsUrls = [];

        foreach ($events as $event) {
            if ($this->currentUser()->can('update', $event)) {
                $eventEditUrls[(string) $event->getKey()] = EventResource::getUrl(
                    'edit',
                    ['record' => $event],
                    panel: 'ahli',
                );
                $eventScheduleUrls[(string) $event->getKey()] = route(
                    'dashboard.events.schedule',
                    ['event' => $event->getKey()],
                );
            }

            if ($this->currentUser()->can('manageAdmissions', $event)) {
                $eventOfflineAdmissionsUrls[(string) $event->getKey()] = route(
                    'dashboard.events.offline-admissions',
                    ['event' => $event->getKey()],
                );
            }
        }

        return view('livewire.pages.dashboard.organizations.workspace', [
            'organization' => $organization,
            'members' => $this->members(),
            'invitations' => $this->invitations(),
            'events' => $events,
            'publicEventIds' => $publicEventIds,
            'eventEditUrls' => $eventEditUrls,
            'eventScheduleUrls' => $eventScheduleUrls,
            'eventOfflineAdmissionsUrls' => $eventOfflineAdmissionsUrls,
            'roleOptions' => $this->roleOptions(),
            'canManageMembers' => $this->canManageMembers(),
            'canManageOrganization' => $this->canManageOrganization(),
        ]);
    }

    /** @return list<string> */
    private function manageableRoles(): array
    {
        return [MemberRole::Admin->value, MemberRole::Editor->value, MemberRole::Viewer->value];
    }

    private function selectedOrganization(): Organization
    {
        $user = $this->currentUser();

        /** @var Organization $organization */
        $organization = Organization::query()
            ->whereKey($this->organizationId)
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->firstOrFail();

        abort_unless(
            app(OrganizationAccess::class)->can((string) $user->getKey(), (string) $organization->getKey(), 'organization.view'),
            403,
        );

        return $organization;
    }

    private function selectedOrganizationAuthorization(string $ability): void
    {
        $organization = $this->selectedOrganization();

        abort_unless(
            app(OrganizationAccess::class)->can((string) $this->currentUser()->getKey(), (string) $organization->getKey(), $ability),
            403,
        );
    }

    private function authorizeManageMembers(Organization $organization): void
    {
        abort_unless(
            app(OrganizationAccess::class)->can(
                (string) $this->currentUser()->getKey(),
                (string) $organization->getKey(),
                'organization.manage-members',
            ),
            403,
        );
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function memberOrFail(Organization $organization, string $memberId): User
    {
        $member = $organization->members()->whereKey($memberId)->firstOrFail();

        abort_unless($member instanceof User, 404);

        return $member;
    }
}
