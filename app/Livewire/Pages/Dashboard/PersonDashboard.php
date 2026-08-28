<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\Membership\Actions\ChangeMemberRoleAction;
use AIArmada\Membership\Actions\RemoveMemberAction;
use AIArmada\Membership\Actions\RevokeInvitationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Membership\InviteSubjectMember;
use App\Actions\Membership\TransferPersonOwnershipAction;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Event;
use App\Models\MemberInvitation;
use App\Models\Person;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Timezone\UserDateTimeFormatter;
use BackedEnum;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts.app')]
#[Title('Speaker workspace')]
final class PersonDashboard extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    public string $personId = '';

    #[Url(as: 'event_search', except: '')]
    public string $eventSearch = '';

    #[Url(as: 'event_status', except: 'all')]
    public string $eventStatus = 'all';

    #[Url(as: 'event_per_page', except: 8)]
    public int $eventPerPage = 8;

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
        $this->eventStatus = $this->normalizeEventStatus($this->eventStatus);
        $this->eventPerPage = $this->normalizeEventPerPage($this->eventPerPage);
    }

    public function updatedEventSearch(): void
    {
        $this->resetPage('person_events_page');
    }

    public function updatedEventStatus(string $value): void
    {
        $this->eventStatus = $this->normalizeEventStatus($value);
        $this->resetPage('person_events_page');
    }

    public function updatedEventPerPage(int|string $value): void
    {
        $this->eventPerPage = $this->normalizeEventPerPage($value);
        $this->resetPage('person_events_page');
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

        if ($person->members()->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
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

        DB::transaction(function () use ($changeMemberRole, $member, $person, $validated): void {
            $person->lockForMembershipMutation();

            OwnerContext::withOwner($person, function () use ($changeMemberRole, $member, $person, $validated): void {
                $changeMemberRole->handle($person, $member, MemberRole::from((string) $validated['editingRole']));
            });
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
            DB::transaction(function () use ($member, $person, $removeMember): void {
                $person->lockForMembershipMutation();

                OwnerContext::withOwner($person, function () use ($member, $person, $removeMember): void {
                    $removeMember->handle($person, $member);
                });
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

    public function transferOwnership(string $memberId, TransferPersonOwnershipAction $transferOwnership): void
    {
        $person = $this->selectedPerson();
        $user = $this->currentUser();

        abort_unless(app(MemberPermissionGate::class)->canPerson($user, 'person.transfer-ownership', $person), 403);

        $member = $this->memberOrFail($person, $memberId);

        OwnerContext::withOwner($person, function () use ($member, $person, $transferOwnership, $user): void {
            $transferOwnership->handle($person, $user, $member);
        });

        $this->successToast(__('Speaker ownership transferred.'));
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

    public function canTransferOwnership(): bool
    {
        return app(MemberPermissionGate::class)->canPerson(
            $this->currentUser(),
            'person.transfer-ownership',
            $this->selectedPerson(),
        );
    }

    public function canEditPerson(): bool
    {
        return $this->currentUser()->can('update', $this->selectedPerson());
    }

    /** @return array<string, string> */
    public function eventStatusOptions(): array
    {
        return [
            'all' => __('All statuses'),
            'draft' => __('Draft'),
            'pending' => __('Pending'),
            'needs_changes' => __('Needs Changes'),
            'approved' => __('Approved'),
            'rejected' => __('Rejected'),
            'cancelled' => __('Cancelled'),
        ];
    }

    /** @return array{total:int,upcoming:int,needs_attention:int} */
    public function eventStats(): array
    {
        $eventsTable = (new Event)->getTable();
        $events = $this->personEventQuery();

        return [
            'total' => (clone $events)->count(),
            'upcoming' => (clone $events)
                ->whereNotNull("{$eventsTable}.starts_at")
                ->where("{$eventsTable}.starts_at", '>=', now())
                ->count(),
            'needs_attention' => (clone $events)
                ->whereIn("{$eventsTable}.status", ['draft', 'pending', 'needs_changes'])
                ->count(),
        ];
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function events(): LengthAwarePaginator
    {
        $eventsTable = (new Event)->getTable();
        $search = trim($this->eventSearch);

        $query = $this->personEventQuery()
            ->with([
                'institution:id,name',
                'primaryLocation.venueSpace:id,name',
            ])
            ->withCount(['registrations as dashboard_registrations_count']);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $eventQuery) use ($eventsTable, $like): void {
                $eventQuery
                    ->whereLike("{$eventsTable}.title", $like)
                    ->orWhereHas('institution', fn (Builder $institutionQuery): Builder => $institutionQuery->whereLike('name', $like));
            });
        }

        if ($this->eventStatus !== 'all') {
            $query->where("{$eventsTable}.status", $this->eventStatus);
        }

        return $query
            ->orderByRaw("CASE WHEN {$eventsTable}.status IN ('draft', 'pending', 'needs_changes') THEN 0 ELSE 1 END")
            ->orderByDesc("{$eventsTable}.starts_at")
            ->orderByDesc("{$eventsTable}.updated_at")
            ->paginate(
                perPage: $this->normalizeEventPerPage($this->eventPerPage),
                pageName: 'person_events_page',
            );
    }

    public function canManageEvents(): bool
    {
        return app(MemberPermissionGate::class)->canPerson(
            $this->currentUser(),
            'event.update',
            $this->selectedPerson(),
        );
    }

    public function canCreateEvents(): bool
    {
        return app(MemberPermissionGate::class)->canPerson(
            $this->currentUser(),
            'event.create',
            $this->selectedPerson(),
        );
    }

    public function canEditEvent(Event $event): bool
    {
        return $this->currentUser()->can('update', $event);
    }

    public function eventStatusLabel(mixed $status): string
    {
        if ($status instanceof HasLabel) {
            return $status->getLabel();
        }

        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        if (! is_scalar($status)) {
            return '';
        }

        $value = (string) $status;
        $translated = __($value);

        return $translated !== $value
            ? $translated
            : str($value)->replace('_', ' ')->headline()->toString();
    }

    public function formatEventSchedule(Event $event): string
    {
        if (! $event->starts_at) {
            return __('TBC');
        }

        $date = UserDateTimeFormatter::translatedFormat($event->starts_at, 'd M Y');
        $time = $event->isPrayerRelative()
            ? (string) $event->timing_display
            : UserDateTimeFormatter::translatedFormat($event->starts_at, 'h:i A');

        return $date.', '.$time;
    }

    public function render(): View
    {
        $person = $this->selectedPerson();
        $events = $this->events();
        $eventEditUrls = [];

        foreach ($events->items() as $event) {
            if (! $event instanceof Event || ! $this->canEditEvent($event)) {
                continue;
            }

            $eventEditUrls[(string) $event->getKey()] = EventResource::getUrl(
                'edit',
                ['record' => $event],
                panel: 'ahli',
            );
        }

        return view('livewire.pages.dashboard.person-dashboard', [
            'person' => $person,
            'personBio' => $this->localizedBio($person),
            'events' => $events,
            'eventStats' => $this->eventStats(),
            'canManageEvents' => $this->canManageEvents(),
            'canCreateEvents' => $this->canCreateEvents(),
            'eventEditUrls' => $eventEditUrls,
            'members' => $this->members(),
            'invitations' => $this->invitations(),
            'roleOptions' => $this->roleOptions(),
            'canManageMembers' => $this->canManageMembers(),
            'canTransferOwnership' => $this->canTransferOwnership(),
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

    private function normalizeEventStatus(string $value): string
    {
        return array_key_exists($value, $this->eventStatusOptions())
            ? $value
            : 'all';
    }

    private function normalizeEventPerPage(int|string $value): int
    {
        $perPage = (int) $value;

        return in_array($perPage, [8, 15, 25], true) ? $perPage : 8;
    }

    /** @return Builder<Event> */
    private function personEventQuery(): Builder
    {
        $personId = $this->selectedPerson()->getKey();

        // Profile membership grants workspace visibility, including private
        // and draft events. Public-route visibility is handled per event.
        return Event::query()->whereHas('persons', function (Builder $personQuery) use ($personId): void {
            $personQuery->whereKey($personId);
        });
    }
}
