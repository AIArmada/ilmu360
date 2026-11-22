<?php

namespace App\Features\Institutions\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Contributions\Api\SubmissionEntityAccess;
use App\Features\Events\Api\Data\InstitutionDashboardEventCard;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Api\InstitutionEventTable;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MemberPermissions;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MemberSubjectType;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class InstitutionDashboard extends Component implements HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable {
        bootedInteractsWithTable as protected filamentBootedInteractsWithTable;
    }
    use InteractsWithToasts;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[Url(as: 'institution')]
    public ?string $institutionId = null;

    #[Url(as: 'event_search', except: '')]
    public string $eventSearch = '';

    #[Url(as: 'event_status', except: 'all')]
    public string $eventStatus = 'all';

    #[Url(as: 'event_visibility', except: 'all')]
    public string $eventVisibility = 'all';

    #[Url(as: 'event_sort', except: 'starts_desc')]
    public string $eventSort = 'starts_desc';

    #[Url(as: 'event_per_page', except: 8)]
    public int $eventPerPage = 8;

    public bool $eventListPage = false;

    public string $newMemberEmail = '';

    public string $newMemberRoleId = '';

    public ?string $editingMemberId = null;

    public string $editingMemberRoleId = '';

    public function mount(): void
    {
        $this->eventListPage = request()->routeIs('dashboard.institutions.events');

        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if (! $this->availableInstitutionsQuery($user)->exists()) {
            abort(403);
        }

        $this->institutionId = $this->normalizeInstitutionId($this->institutionId);

        $this->institutionId ??= $this->availableInstitutionsQuery($user)
            ->orderBy('name')
            ->value('institutions.id');

        if ($this->institutionId !== null && ! $this->availableInstitutionsQuery($user)->whereKey($this->institutionId)->exists()) {
            abort(403);
        }

        $this->eventStatus = $this->normalizeEventStatus($this->eventStatus);
        $this->eventVisibility = $this->normalizeEventVisibility($this->eventVisibility);
        $this->eventSort = $this->normalizeEventSort($this->eventSort);
        $this->eventPerPage = $this->normalizeEventPerPage($this->eventPerPage);
    }

    public function bootedInteractsWithTable(): void
    {
        $this->filamentBootedInteractsWithTable();
        $this->syncTableStateFromDashboardFilters();
    }

    public function getTablePaginationPageName(): string
    {
        return 'institution_events_page';
    }

    public function updatedInstitutionId(?string $institutionId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->institutionId = $this->normalizeInstitutionId($institutionId);

        if ($this->institutionId === null) {
            $this->flushCachedTableRecords();
            $this->resetPage();
            $this->resetPage('institution_members_page');
            $this->resetMemberEditor();

            return;
        }

        if (! $this->availableInstitutionsQuery($user)->whereKey($this->institutionId)->exists()) {
            abort(403);
        }

        $this->flushCachedTableRecords();
        $this->resetPage();
        $this->resetPage('institution_members_page');
        $this->resetMemberEditor();
    }

    public function updatedEventSort(string $value): void
    {
        $this->eventSort = $this->normalizeEventSort($value);
        $this->tableSort = null;

        $this->flushCachedTableRecords();
        $this->resetPage();
    }

    public function updated(string $name, mixed $value): void
    {
        if ($name === 'tableSearch') {
            $this->eventSearch = $this->tableSearch ?? '';

            return;
        }

        if (str($name)->startsWith('tableFilters')) {
            $this->syncDashboardFiltersFromTable();

            return;
        }

        if ($name === 'tableSort') {
            $this->eventSort = $this->dashboardEventSortFromTableState();

            return;
        }

        if ($name === 'tableRecordsPerPage') {
            $this->eventPerPage = $this->normalizeEventPerPage($this->tableRecordsPerPage ?? 8);
        }
    }

    public function addMember(): void
    {
        $institution = $this->selectedInstitutionOrAbort();

        $this->ensureCanManageMembers($institution);

        $validated = $this->validate($this->memberCreationRules());

        $email = mb_strtolower(trim((string) $validated['newMemberEmail']));
        $member = User::query()
            ->whereLike('email', $email)
            ->first();

        if (! $member instanceof User) {
            throw ValidationException::withMessages([
                'newMemberEmail' => __('We could not find a user with that email address.'),
            ]);
        }

        $role = MemberRole::tryFrom((string) $validated['newMemberRoleId']) ?? MemberRole::Viewer;

        OwnerContext::withOwner($institution, fn () => app(MembershipCommands::class)->addMember(
            MemberSubjectType::Institution,
            (string) $institution->getKey(),
            (string) $member->getKey(),
            $role->value,
        ));

        $this->newMemberEmail = '';
        $this->newMemberRoleId = '';
        $this->resetPage('institution_members_page');

        $this->successToast(__('Member added successfully.'));
    }

    public function startEditingMemberRoles(string $memberId): void
    {
        $institution = $this->selectedInstitutionOrAbort();

        $this->ensureCanManageMembers($institution);

        $member = $this->findInstitutionMember($memberId);

        if ($this->memberHasProtectedRole($institution, $member)) {
            $this->errorToast(__('Owner roles can only be changed from the global roles screen.'));

            return;
        }

        $this->editingMemberId = $member->id;
        $this->editingMemberRoleId = $this->getMemberRoleIds($member)[0] ?? '';
    }

    public function cancelEditingMemberRoles(): void
    {
        $this->resetMemberEditor();
    }

    public function saveMemberRoles(): void
    {
        $institution = $this->selectedInstitutionOrAbort();

        $this->ensureCanManageMembers($institution);

        if (! is_string($this->editingMemberId) || $this->editingMemberId === '') {
            abort(404);
        }

        $member = $this->findInstitutionMember($this->editingMemberId);

        if ($this->memberHasProtectedRole($institution, $member)) {
            $this->resetMemberEditor();
            $this->errorToast(__('Owner roles can only be changed from the global roles screen.'));

            return;
        }

        $validated = $this->validate($this->memberRoleRules());

        $role = MemberRole::tryFrom((string) $validated['editingMemberRoleId']) ?? MemberRole::Viewer;

        app(MembershipCommands::class)->changeMemberRole(
            MemberSubjectType::Institution,
            (string) $institution->getKey(),
            (string) $member->getKey(),
            $role->value,
        );

        $this->resetMemberEditor();

        $this->successToast(__('Member roles updated.'));
    }

    public function removeMember(string $memberId): void
    {
        $institution = $this->selectedInstitutionOrAbort();

        $this->ensureCanManageMembers($institution);

        $member = $this->findInstitutionMember($memberId);

        if ($this->memberIsOwner($institution, $member)) {
            $this->errorToast(__('Institution owners cannot be removed from this dashboard.'));

            return;
        }

        app(MembershipCommands::class)->removeMember(
            MemberSubjectType::Institution,
            (string) $institution->getKey(),
            (string) $member->getKey(),
        );

        if ($this->editingMemberId === $member->id) {
            $this->resetMemberEditor();
        }

        $this->resetPage('institution_members_page');

        $this->successToast(__('Member removed successfully.'));
    }

    /**
     * @return Collection<int, Institution>
     */
    #[Computed]
    public function institutions(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        $institutions = $this->availableInstitutionsQuery($user)
            ->with('media')
            ->withCount([
                'members',
                'follows as followers_count' => fn (Builder $query): Builder => $query->where('status', 'active'),
            ])
            ->orderBy('name')
            ->get();

        $stats = app(EventScheduleReads::class)->institutionEventStats(
            $institutions
                ->map(static fn (Institution $institution): string => (string) $institution->getKey())
                ->all(),
        );

        return $institutions->each(function (Institution $institution) use ($stats): void {
            $stat = $stats[(string) $institution->getKey()] ?? [
                'total' => 0,
                'public' => 0,
                'upcoming' => 0,
                'draft' => 0,
                'pending' => 0,
            ];

            $institution->setAttribute('events_count', $stat['total']);
            $institution->setAttribute('public_events_count', $stat['public']);
            $institution->setAttribute('upcoming_events_count', $stat['upcoming']);
            $institution->setAttribute('draft_events_count', $stat['draft']);
            $institution->setAttribute('pending_events_count', $stat['pending']);
        });
    }

    #[Computed]
    public function selectedInstitution(): ?Institution
    {
        if ($this->institutionId === null || $this->institutionId === '') {
            return null;
        }

        /** @var Institution|null $institution */
        $institution = $this->institutions()
            ->firstWhere('id', $this->institutionId);

        return $institution;
    }

    #[Computed]
    public function canUseSelectedInstitutionForScopedSubmission(): bool
    {
        return $this->selectedInstitutionSupportsScopedSubmission();
    }

    /**
     * @return array{events_count:int,public_events_count:int,internal_events_count:int,upcoming_events_count:int,draft_events_count:int,pending_events_count:int,members_count:int,followers_count:int,registrations_count:int}
     */
    #[Computed]
    public function institutionStats(): array
    {
        $institution = $this->selectedInstitution();

        if (! $institution instanceof Institution) {
            return [
                'events_count' => 0,
                'public_events_count' => 0,
                'internal_events_count' => 0,
                'upcoming_events_count' => 0,
                'draft_events_count' => 0,
                'pending_events_count' => 0,
                'members_count' => 0,
                'followers_count' => 0,
                'registrations_count' => 0,
            ];
        }

        $totalEvents = (int) ($institution->events_count ?? 0);
        $publicEvents = (int) ($institution->public_events_count ?? 0);
        $registrationsCount = app(EventScheduleReads::class)->institutionRegistrationCount((string) $institution->id);

        return [
            'events_count' => $totalEvents,
            'public_events_count' => $publicEvents,
            'internal_events_count' => max($totalEvents - $publicEvents, 0),
            'upcoming_events_count' => (int) ($institution->upcoming_events_count ?? 0),
            'draft_events_count' => (int) ($institution->draft_events_count ?? 0),
            'pending_events_count' => (int) ($institution->pending_events_count ?? 0),
            'members_count' => (int) ($institution->members_count ?? 0),
            'followers_count' => (int) ($institution->followers_count ?? 0),
            'registrations_count' => $registrationsCount,
        ];
    }

    /** @return Collection<int, InstitutionDashboardEventCard> */
    #[Computed]
    public function recentInstitutionEvents(): Collection
    {
        $institution = $this->selectedInstitution();

        if (! $institution instanceof Institution) {
            return collect();
        }

        return collect(app(EventScheduleReads::class)->recentInstitutionEventCards((string) $institution->getKey(), 7));
    }

    /** @return Collection<int, InstitutionDashboardEventCard> */
    #[Computed]
    public function pendingInstitutionEvents(): Collection
    {
        $institution = $this->selectedInstitution();

        if (! $institution instanceof Institution) {
            return collect();
        }

        return collect(app(EventScheduleReads::class)->pendingInstitutionEventCards(
            institutionId: (string) $institution->getKey(),
            search: $this->eventSearch,
            status: $this->eventStatus,
            visibility: $this->eventVisibility,
            limit: 2,
        ));
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function institutionMembers(): LengthAwarePaginator
    {
        $institution = $this->selectedInstitution();

        if (! $institution instanceof Institution) {
            return User::query()
                ->whereRaw('1 = 0')
                ->paginate(perPage: 8, pageName: 'institution_members_page');
        }

        return User::query()
            ->whereIn('id', $institution->members()->select('users.id'))
            ->orderBy('name')
            ->paginate(perPage: 8, pageName: 'institution_members_page');
    }

    /**
     * @return array<string, list<string>>
     */
    #[Computed]
    public function institutionMemberRoleMap(): array
    {
        $roleMap = [];

        foreach ($this->institutionMembers()->items() as $member) {
            if (! $member instanceof User) {
                continue;
            }

            $roleMap[$member->id] = $this->getMemberRoleNames($member);
        }

        return $roleMap;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function eventStatusOptions(): array
    {
        return [
            'all' => __('All statuses'),
            'pending' => __('Pending'),
            'draft' => __('Draft'),
            'approved' => __('Approved'),
            'needs_changes' => __('Needs Changes'),
            'rejected' => __('Rejected'),
            'cancelled' => __('Cancelled'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function eventVisibilityOptions(): array
    {
        return [
            'all' => __('All visibility'),
            EventVisibility::Public->value => __('Public'),
            EventVisibility::Unlisted->value => __('Unlisted'),
            EventVisibility::Private->value => __('Hidden'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function eventSortOptions(): array
    {
        return [
            'starts_desc' => __('Newest first'),
            'starts_asc' => __('Oldest first'),
            'title_asc' => __('Title A-Z'),
            'title_desc' => __('Title Z-A'),
            'registrations_desc' => __('Most registrations'),
            'pending_first' => __('Pending first'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function institutionRoleOptions(): array
    {
        return collect(MemberRole::cases())
            ->mapWithKeys(fn (MemberRole $r): array => [$r->value => $r->label()])
            ->all();
    }

    #[Computed]
    public function canManageMembers(): bool
    {
        $institution = $this->selectedInstitution();
        $user = auth()->user();

        return $institution instanceof Institution
            && $user instanceof User
            && $this->userHasInstitutionManagementRole($user, $institution);
    }

    public function table(Table $table): Table
    {
        $institution = $this->selectedInstitution();

        return app(InstitutionEventTable::class)->configure(
            table: $table,
            institutionId: $institution instanceof Institution ? (string) $institution->getKey() : null,
            selectedInstitutionId: $this->institutionId,
            canUseSelectedInstitutionForScopedSubmission: $this->canUseSelectedInstitutionForScopedSubmission(),
            sortState: fn (): array => [
                'sort' => $this->eventSort,
                'hasExplicitTableSort' => filled($this->tableSort),
            ],
            statusOptions: collect($this->eventStatusOptions())->except('all')->all(),
            visibilityOptions: collect($this->eventVisibilityOptions())->except('all')->all(),
        );
    }

    /**
     * @return Builder<Institution>
     */
    protected function availableInstitutionsQuery(User $user): Builder
    {
        return Institution::query()->whereKey(
            app(MembershipDirectory::class)->subjectIdsFor(
                (string) $user->getKey(),
                MemberSubjectType::Institution,
            ),
        );
    }

    protected function selectedInstitutionSupportsScopedSubmission(): bool
    {
        $user = auth()->user();
        $institution = $this->selectedInstitution();

        if (! $user instanceof User || ! $institution instanceof Institution) {
            return false;
        }

        return app(SubmissionEntityAccess::class)->canUseMemberInstitution($user, (string) $institution->getKey());
    }

    protected function normalizeInstitutionId(?string $institutionId): ?string
    {
        if ($institutionId === null || trim($institutionId) === '') {
            return null;
        }

        return $institutionId;
    }

    protected function normalizeEventStatus(string $value): string
    {
        return array_key_exists($value, $this->eventStatusOptions())
            ? $value
            : 'all';
    }

    protected function normalizeEventVisibility(string $value): string
    {
        return array_key_exists($value, $this->eventVisibilityOptions())
            ? $value
            : 'all';
    }

    protected function normalizeEventSort(string $value): string
    {
        return array_key_exists($value, $this->eventSortOptions())
            ? $value
            : 'starts_desc';
    }

    protected function normalizeEventPerPage(int|string $value): int
    {
        $perPage = (int) $value;

        return in_array($perPage, [8, 15, 25], true) ? $perPage : 8;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    protected function memberCreationRules(): array
    {
        return [
            'newMemberEmail' => ['required', 'email'],
            'newMemberRoleId' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    protected function memberRoleRules(): array
    {
        return [
            'editingMemberRoleId' => ['required', 'string'],
        ];
    }

    protected function selectedInstitutionOrAbort(): Institution
    {
        $institution = $this->selectedInstitution();

        abort_unless($institution instanceof Institution, 404);

        return $institution;
    }

    protected function findInstitutionMember(string $memberId): User
    {
        $member = $this->selectedInstitutionOrAbort()
            ->members()
            ->whereKey($memberId)
            ->first();

        abort_unless($member instanceof User, 404);

        return $member;
    }

    protected function resetMemberEditor(): void
    {
        $this->editingMemberId = null;
        $this->editingMemberRoleId = '';
    }

    protected function ensureCanManageMembers(Institution $institution): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $this->userHasInstitutionManagementRole($user, $institution), 403);
    }

    /**
     * Mirror URL/query dashboard filters into Filament table state.
     */
    protected function syncTableStateFromDashboardFilters(): void
    {
        $this->eventStatus = $this->normalizeEventStatus($this->eventStatus);
        $this->eventVisibility = $this->normalizeEventVisibility($this->eventVisibility);
        $this->eventSort = $this->normalizeEventSort($this->eventSort);
        $this->eventPerPage = $this->normalizeEventPerPage($this->eventPerPage);

        $this->tableSearch = $this->eventSearch;
        $this->tableFilters = array_filter([
            'status' => $this->eventStatus === 'all' ? null : ['value' => $this->eventStatus],
            'visibility' => $this->eventVisibility === 'all' ? null : ['value' => $this->eventVisibility],
        ], fn (?array $filter): bool => $filter !== null);
        $this->tableDeferredFilters = $this->tableFilters;
        $this->tableRecordsPerPage = $this->eventPerPage;

        $this->getTableFiltersForm()->fill($this->tableFilters);
    }

    /**
     * Mirror Filament table filters back to dashboard URL/query state.
     */
    protected function syncDashboardFiltersFromTable(): void
    {
        $this->eventStatus = $this->normalizeEventStatus((string) (data_get($this->getTableFilterState('status'), 'value') ?? 'all'));
        $this->eventVisibility = $this->normalizeEventVisibility((string) (data_get($this->getTableFilterState('visibility'), 'value') ?? 'all'));
    }

    protected function dashboardEventSortFromTableState(): string
    {
        return match ($this->tableSort) {
            'starts_at:asc', 'starts_at' => 'starts_asc',
            'starts_at:desc' => 'starts_desc',
            'title:asc', 'title' => 'title_asc',
            'title:desc' => 'title_desc',
            null, '' => 'starts_desc',
            default => 'starts_desc',
        };
    }

    /**
     * @return list<string>
     */
    protected function getMemberRoleIds(User $user): array
    {
        $institution = $this->selectedInstitution();
        if (! $institution instanceof Institution) {
            return [];
        }
        $member = $institution->members()->where('user_id', $user->id)->first();
        if (! $member) {
            return [];
        }

        $roleSlug = $member->pivot->role ?? '';

        return $roleSlug !== '' ? [$roleSlug] : [];
    }

    /**
     * @return list<string>
     */
    protected function getMemberRoleNames(User $user): array
    {
        $institution = $this->selectedInstitution();
        if (! $institution instanceof Institution) {
            return [];
        }
        $member = $institution->members()->where('user_id', $user->id)->first();
        if (! $member) {
            return [];
        }

        $roleSlug = $member->pivot->role ?? '';

        if ($roleSlug === '') {
            return [];
        }

        return [MemberRole::tryFrom($roleSlug)?->label() ?? $roleSlug];
    }

    protected function userHasInstitutionManagementRole(User $user, Institution $institution): bool
    {
        return app(MemberPermissions::class)->canSubject($user, 'institution.manage-members', MemberSubjectType::Institution, (string) $institution->getKey());
    }

    protected function memberIsOwner(Institution $institution, User $user): bool
    {
        return $institution->members()
            ->whereKey($user->getKey())
            ->wherePivot('role', MemberRole::Owner->value)
            ->exists();
    }

    protected function memberHasProtectedRole(Institution $institution, User $user): bool
    {
        return $this->memberIsOwner($institution, $user);
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.institution-dashboard');
    }
}
