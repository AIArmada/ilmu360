<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Actions\RemoveMemberAction;
use AIArmada\Membership\Actions\RevokeInvitationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Membership\InviteSubjectMember;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\MemberInvitation;
use App\Models\Person;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
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
#[Title('Speaker workspace')]
final class PersonDashboard extends Component
{
    use InteractsWithToasts;

    public string $personId = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'viewer';

    public ?string $editingMemberId = null;

    public string $editingRole = 'viewer';

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Person $person): void
    {
        $user = $this->currentUser();

        abort_unless(app(MemberPermissionGate::class)->canPerson($user, 'person.view', $person), 403);

        $this->personId = (string) $person->getKey();
    }

    public function invite(InviteSubjectMember $inviteSubjectMember): void
    {
        $person = $this->selectedPerson();
        $user = $this->currentUser();
        $this->authorizeManageMembers($person);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:255'],
            'inviteRole' => ['required', Rule::in($this->manageableRoles())],
        ]);

        $email = mb_strtolower(trim((string) $validated['inviteEmail']));

        if ($person->members()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages([
                'inviteEmail' => __('This person is already a member of the speaker profile.'),
            ]);
        }

        $pendingInvitationExists = $this->invitationQuery($person)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
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
            $person,
            $email,
            (string) $validated['inviteRole'],
            $user,
        );

        $this->reset('inviteEmail');
        $this->successToast(__('Invitation sent successfully.'));
    }

    public function startEditingMember(string $memberId): void
    {
        $person = $this->selectedPerson();
        $this->authorizeManageMembers($person);

        $member = $this->memberOrFail($person, $memberId);
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
        $person = $this->selectedPerson();
        $this->authorizeManageMembers($person);

        if ($this->editingMemberId === null) {
            abort(404);
        }

        $validated = $this->validate([
            'editingRole' => ['required', Rule::in($this->manageableRoles())],
        ]);
        $member = $this->memberOrFail($person, $this->editingMemberId);
        $role = MemberRole::fromSpatieRoleName((string) data_get($member->getRelationValue('pivot'), 'role'));

        if ($role === MemberRole::Owner) {
            $this->resetMemberEditor();
            $this->errorToast(__('Transfer ownership before changing the owner role.'));

            return;
        }

        OwnerContext::withOwner($person, function () use ($changeMemberRole, $member, $person, $validated): void {
            $changeMemberRole->handle($person, $member, MemberRole::from((string) $validated['editingRole']));
        });

        $this->resetMemberEditor();
        $this->successToast(__('Member role updated.'));
    }

    public function removeMember(string $memberId, RemoveMemberAction $removeMember): void
    {
        $person = $this->selectedPerson();
        $this->authorizeManageMembers($person);
        $member = $this->memberOrFail($person, $memberId);
        $role = MemberRole::fromSpatieRoleName((string) data_get($member->getRelationValue('pivot'), 'role'));

        if ($role === MemberRole::Owner) {
            $this->errorToast(__('Speaker owners cannot be removed from this dashboard.'));

            return;
        }

        try {
            OwnerContext::withOwner($person, function () use ($member, $person, $removeMember): void {
                $removeMember->handle($person, $member);
            });
        } catch (Throwable $throwable) {
            report($throwable);
            $this->errorToast(__('This member cannot be removed. Transfer ownership first if needed.'));

            return;
        }

        $this->successToast(__('Member removed successfully.'));
    }

    public function revokeInvitation(string $invitationId, RevokeInvitationAction $revokeInvitation): void
    {
        $person = $this->selectedPerson();
        $user = $this->currentUser();
        $this->authorizeManageMembers($person);

        $invitation = $this->invitationQuery($person)->whereKey($invitationId)->firstOrFail();
        $revokeInvitation->handle($invitation, $user);

        $this->successToast(__('Invitation revoked.'));
    }

    /** @return Collection<int, User> */
    public function members(): Collection
    {
        $person = $this->selectedPerson();

        return OwnerContext::withOwner($person, fn (): Collection => $person->members()->orderBy('name')->get());
    }

    /** @return Collection<int, MemberInvitation> */
    public function invitations(): Collection
    {
        return $this->invitationQuery($this->selectedPerson())
            ->latest()
            ->limit(20)
            ->get();
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
        return app(MemberPermissionGate::class)->canPerson(
            $this->currentUser(),
            'person.manage-members',
            $this->selectedPerson(),
        );
    }

    public function canEditPerson(): bool
    {
        return $this->currentUser()->can('update', $this->selectedPerson());
    }

    public function render(): View
    {
        $person = $this->selectedPerson();

        return view('livewire.pages.dashboard.person-dashboard', [
            'person' => $person,
            'personBio' => $this->localizedBio($person),
            'members' => $this->members(),
            'invitations' => $this->invitations(),
            'roleOptions' => $this->roleOptions(),
            'canManageMembers' => $this->canManageMembers(),
            'canEditPerson' => $this->canEditPerson(),
        ]);
    }

    /** @return list<string> */
    private function manageableRoles(): array
    {
        return [MemberRole::Admin->value, MemberRole::Editor->value, MemberRole::Viewer->value];
    }

    private function selectedPerson(): Person
    {
        /** @var Person $person */
        $person = Person::query()->whereKey($this->personId)->firstOrFail();

        abort_unless(app(MemberPermissionGate::class)->canPerson($this->currentUser(), 'person.view', $person), 403);

        return $person;
    }

    private function authorizeManageMembers(Person $person): void
    {
        abort_unless(app(MemberPermissionGate::class)->canPerson($this->currentUser(), 'person.manage-members', $person), 403);
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function memberOrFail(Person $person, string $memberId): User
    {
        $member = $person->members()->whereKey($memberId)->firstOrFail();

        abort_unless($member instanceof User, 404);

        return $member;
    }

    /** @return Builder<MemberInvitation> */
    private function invitationQuery(Person $person): Builder
    {
        return MemberInvitation::query()
            ->where('subject_type', MemberSubjectType::Person->value)
            ->where('subject_id', $person->getKey());
    }

    private function resetMemberEditor(): void
    {
        $this->editingMemberId = null;
        $this->editingRole = MemberRole::Viewer->value;
    }

    private function localizedBio(Person $person): string
    {
        $bio = $person->bio;

        if (is_array($bio)) {
            $bio = data_get($bio, app()->getLocale()) ?: data_get($bio, 'en');
        }

        return is_string($bio) && trim($bio) !== ''
            ? $bio
            : __('Manage this speaker profile and its trusted members.');
    }
}
