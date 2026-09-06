<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Organizations;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Actions\RemoveMemberAction;
use AIArmada\Membership\Actions\RevokeInvitationAction;
use AIArmada\Membership\Enums\InvitationStatus;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Organizations\Actions\ArchiveOrganizationAction;
use AIArmada\Organizations\Actions\MakeOrganizationPrivateAction;
use AIArmada\Organizations\Actions\MakeOrganizationPublicAction;
use AIArmada\Organizations\Actions\RestoreOrganizationAction;
use AIArmada\Organizations\Actions\SuspendOrganizationAction;
use AIArmada\Organizations\Actions\TransferOrganizationOwnershipAction;
use AIArmada\Organizations\Contracts\OrganizationAuthorization;
use AIArmada\Organizations\Models\Organization;
use App\Actions\Membership\InviteSubjectMember;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Event;
use App\Models\MemberInvitation;
use App\Models\User;
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

        app(OrganizationAuthorization::class)->authorize($user, $organization, 'organization.view');

        $this->organizationId = (string) $organization->getKey();
    }

    public function invite(InviteSubjectMember $inviteSubjectMember, OrganizationAuthorization $authorization): void
    {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $authorization->authorize($user, $organization, 'organization.manage-members');

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

        $pendingInvitationExists = $this->invitationQuery($organization)
            ->where('email', $email)
            ->where('status', InvitationStatus::Pending->value)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($pendingInvitationExists) {
            throw ValidationException::withMessages([
                'inviteEmail' => __('There is already a pending invitation for this email address.'),
            ]);
        }

        $inviteSubjectMember->handle(
            $organization,
            $email,
            (string) $validated['inviteRole'],
            $user,
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

    public function saveMemberRole(ChangeMemberRoleAction $changeMemberRole): void
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

        OwnerContext::withOwner($organization, function () use ($changeMemberRole, $member, $organization, $validated): void {
            $changeMemberRole->handle($organization, $member, MemberRole::from((string) $validated['editingRole']));
        });

        $this->cancelEditingMember();
        $this->successToast(__('Member role updated.'));
    }

    public function removeMember(string $memberId, RemoveMemberAction $removeMember): void
    {
        $organization = $this->selectedOrganization();
        $this->authorizeManageMembers($organization);
        $member = $this->memberOrFail($organization, $memberId);

        try {
            OwnerContext::withOwner($organization, function () use ($member, $organization, $removeMember): void {
                $removeMember->handle($organization, $member);
            });
        } catch (Throwable $throwable) {
            report($throwable);
            $this->errorToast(__('This member cannot be removed. Transfer ownership first if needed.'));

            return;
        }

        $this->successToast(__('Member removed successfully.'));
    }

    public function transferOwnership(string $memberId, TransferOrganizationOwnershipAction $transferOwnership): void
    {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        $member = $this->memberOrFail($organization, $memberId);

        OwnerContext::withOwner($organization, function () use ($member, $organization, $transferOwnership, $user): void {
            $transferOwnership->handle($organization, $user, $member);
        });

        $this->successToast(__('Organization ownership transferred.'));
    }

    public function revokeInvitation(string $invitationId, RevokeInvitationAction $revokeInvitation): void
    {
        $organization = $this->selectedOrganization();
        $user = $this->currentUser();
        app(OrganizationAuthorization::class)->authorize($user, $organization, 'organization.manage-members');

        $invitation = $this->invitationQuery($organization)->whereKey($invitationId)->firstOrFail();
        $revokeInvitation->handle($invitation, $user);

        $this->successToast(__('Invitation revoked.'));
    }

    public function makePublic(MakeOrganizationPublicAction $action): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-visibility');
        $action->handle($organization, $this->currentUser());
        $this->successToast(__('Organization is now public.'));
    }

    public function makePrivate(MakeOrganizationPrivateAction $action): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-visibility');
        $action->handle($organization, $this->currentUser());
        $this->successToast(__('Organization is now private.'));
    }

    public function suspend(SuspendOrganizationAction $action): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $action->handle($organization, $this->currentUser());
        $this->successToast(__('Organization suspended.'));
    }

    public function restore(RestoreOrganizationAction $action): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $action->handle($organization, $this->currentUser());
        $this->successToast(__('Organization restored.'));
    }

    public function archive(ArchiveOrganizationAction $action): void
    {
        $organization = $this->selectedOrganization();
        $this->selectedOrganizationAuthorization('organization.change-status');
        $action->handle($organization, $this->currentUser());
        $this->successToast(__('Organization archived.'));
    }

    /** @return Collection<int, User> */
    public function members(): Collection
    {
        $organization = $this->selectedOrganization();

        return OwnerContext::withOwner($organization, fn (): Collection => $organization->members()->orderBy('name')->get());
    }

    /** @return Collection<int, MemberInvitation> */
    public function invitations(): Collection
    {
        return $this->invitationQuery($this->selectedOrganization())
            ->latest()
            ->limit(20)
            ->get();
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

        app(OrganizationAuthorization::class)->authorize($user, $organization, 'organization.view');

        return $organization;
    }

    private function selectedOrganizationAuthorization(string $ability): void
    {
        app(OrganizationAuthorization::class)->authorize($this->currentUser(), $this->selectedOrganization(), $ability);
    }

    private function authorizeManageMembers(Organization $organization): void
    {
        app(OrganizationAuthorization::class)->authorize($this->currentUser(), $organization, 'organization.manage-members');
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

    /** @return Builder<MemberInvitation> */
    private function invitationQuery(Organization $organization): Builder
    {
        return MemberInvitation::query()
            ->where('subject_type', MemberSubjectType::Organization->value)
            ->where('subject_id', $organization->getKey());
    }
}
