<?php

namespace App\Livewire\Pages\Dashboard;

use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\NotificationMessage;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\SavedSearch;
use App\Models\Speaker;
use App\Models\User;
use App\Services\ShareTrackingAnalyticsService;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class UserDashboard extends Component
{
    use WithPagination;

    private const int AGENDA_PER_PAGE = 6;

    private const int MAJLIS_CARD_PER_PAGE = 6;

    private const int PLANNER_BUCKET_PER_PAGE = 3;

    private const int SUBMITTED_PER_PAGE = 4;

    private const int CHECKINS_PER_PAGE = 6;

    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
    }

    /**
     * @return array{going_count: int, saved_count: int}
     */
    #[Computed]
    public function summaryStats(): array
    {
        return [
            'going_count' => $this->countCalendarEntriesForRole('going', futureOnly: true),
            'saved_count' => $this->countCalendarEntriesForRole('saved', futureOnly: true),
        ];
    }

    /**
     * @return array{
     *     visits: int,
     *     unique_visitors: int,
     *     signups: int,
     *     event_registrations: int,
     *     total_outcomes: int
     * }
     */
    #[Computed]
    public function dawahImpactSummary(): array
    {
        return app(ShareTrackingAnalyticsService::class)->summaryForUser($this->user());
    }

    /**
     * @return Collection<int, Event>
     */
    #[Computed]
    public function savedEvents(): Collection
    {
        return $this->sortEventsForPlanner(
            $this->savedEventsQuery($this->user())
                ->with($this->plannerEventRelations())
                ->get()
        );
    }

    /**
     * @return Collection<int, Event>
     */
    #[Computed]
    public function goingEvents(): Collection
    {
        return $this->sortEventsForPlanner(
            $this->goingEventsQuery($this->user())
                ->with($this->plannerEventRelations())
                ->get()
        );
    }

    /**
     * @return Collection<int, Institution>
     */
    #[Computed]
    public function followingInstitutions(): Collection
    {
        /** @var Collection<int, Institution> $institutions */
        $institutions = $this->user()->followingInstitutions()
            ->with(['media'])
            ->orderBy('name')
            ->get();

        return $institutions;
    }

    /**
     * @return Collection<int, Reference>
     */
    #[Computed]
    public function followingReferences(): Collection
    {
        /** @var Collection<int, Reference> $references */
        $references = $this->user()->followingReferences()
            ->with(['media'])
            ->orderBy('title')
            ->get();

        return $references;
    }

    /**
     * @return Collection<int, Speaker>
     */
    #[Computed]
    public function followingSpeakers(): Collection
    {
        /** @var Collection<int, Speaker> $speakers */
        $speakers = $this->user()->followingSpeakers()
            ->with(['media'])
            ->orderBy('name')
            ->get();

        return $speakers;
    }

    /**
     * @return Collection<int, SavedSearch>
     */
    #[Computed]
    public function recentSavedSearches(): Collection
    {
        /** @var Collection<int, SavedSearch> $savedSearches */
        $savedSearches = $this->user()
            ->savedSearches()
            ->latest()
            ->limit(3)
            ->get();

        return $savedSearches;
    }

    /**
     * @return Collection<int, NotificationMessage>
     */
    #[Computed]
    public function recentNotifications(): Collection
    {
        /** @var Collection<int, NotificationMessage> $notifications */
        $notifications = $this->user()
            ->notificationMessages()
            ->visibleInInbox()
            ->limit(3)
            ->get();

        return $notifications;
    }

    #[Computed]
    public function unreadNotificationCount(): int
    {
        return $this->user()
            ->notificationMessages()
            ->visibleInInbox()
            ->whereNull('read_at')
            ->count();
    }

    #[Computed]
    public function recommendedEvent(): ?Event
    {
        /** @var list<string> $excludedEventIds */
        $excludedEventIds = $this->savedEvents()
            ->pluck('id')
            ->merge($this->goingEvents()->pluck('id'))
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        /** @var ?Event $event */
        $event = Event::query()
            ->active()
            ->with($this->plannerEventRelations())
            ->when($excludedEventIds !== [], fn ($query) => $query->whereNotIn('id', $excludedEventIds))
            ->where('starts_at', '>=', now('UTC'))
            ->orderBy('starts_at')
            ->first();

        return $event;
    }

    /**
     * @return Collection<int, array{event: Event, roles: list<string>}>
     */
    #[Computed]
    public function majlisCards(): Collection
    {
        /** @var Collection<string, array{event: Event, roles: list<string>}> $cards */
        $cards = collect();

        foreach ($this->savedEvents() as $event) {
            $roles = ['saved'];

            $cards->put($event->id, [
                'event' => $event,
                'roles' => $roles,
            ]);
        }

        foreach ($this->goingEvents() as $event) {
            $card = $cards->get($event->id, [
                'event' => $event,
                'roles' => [],
            ]);
            $roles = $card['roles'];
            $roles[] = 'going';

            $card['roles'] = collect($roles)->unique()->values()->all();
            $cards->put($event->id, $card);
        }

        /** @var Collection<int, array{event: Event, roles: list<string>}> $sorted */
        $sorted = $cards
            ->values()
            ->sortBy(fn (array $card): int => $card['event']->starts_at?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        return $sorted;
    }

    /**
     * @return LengthAwarePaginator<int, array{event: Event, roles: list<string>}>
     */
    #[Computed]
    public function paginatedMajlisCards(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->majlisCards(), self::MAJLIS_CARD_PER_PAGE, 'majlis_page');
    }

    /**
     * @return Collection<int, Registration>
     */
    #[Computed]
    public function registeredEvents(): Collection
    {
        /** @var Collection<int, Registration> $registrations */
        $registrations = Registration::query()
            ->where('user_id', $this->user()->id)
            ->with([
                'event' => fn ($query) => $query->with($this->plannerEventRelations()),
            ])
            ->latest()
            ->get()
            ->filter(fn (Registration $registration): bool => $registration->event instanceof Event)
            ->values();

        return $this->sortRegistrationsForPlanner($registrations);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function submittedEvents(): Collection
    {
        /** @var Collection<int, array<string, mixed>> $entries */
        $entries = collect();

        $submissions = EventSubmission::query()
            ->where('submitted_by', $this->user()->id)
            ->with([
                'event' => fn ($query) => $query->with($this->plannerEventRelations()),
            ])
            ->latest()
            ->get()
            ->values();

        foreach ($submissions as $submission) {
            if (! $submission instanceof EventSubmission || ! $submission->event instanceof Event) {
                continue;
            }

            $entries->push([
                'event' => $submission->event,
                'created_at' => $submission->created_at,
                'notes' => $submission->notes,
            ]);
        }

        $directEvents = Event::query()
            ->where('submitter_id', $this->user()->id)
            ->with($this->plannerEventRelations())
            ->whereDoesntHave('submissions', fn ($query) => $query->where('submitted_by', $this->user()->id))
            ->latest('created_at')
            ->get();

        foreach ($directEvents as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            $entries->push([
                'event' => $event,
                'created_at' => $event->created_at,
                'notes' => null,
            ]);
        }

        /** @var Collection<int, array<string, mixed>> $sortedEntries */
        $sortedEntries = $entries
            ->sortByDesc(fn (array $entry): int => $entry['created_at']?->getTimestamp() ?? 0)
            ->values();

        return $sortedEntries;
    }

    /**
     * @return Collection<int, EventCheckin>
     */
    #[Computed]
    public function recentCheckins(): Collection
    {
        /** @var Collection<int, EventCheckin> $checkins */
        $checkins = EventCheckin::query()
            ->where('user_id', $this->user()->id)
            ->with([
                'event' => fn ($query) => $query->with($this->plannerEventRelations()),
            ])
            ->orderByDesc('checked_in_at')
            ->get();

        return $checkins;
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function calendarEntries(): array
    {
        /** @var array<string, array<string, mixed>> $entries */
        $entries = [];
        $savedEvents = $this->savedEvents();
        $goingEvents = $this->goingEvents();

        foreach ($savedEvents as $event) {
            $this->mergeEventIntoCalendarEntries($entries, $event, 'saved');
        }

        foreach ($goingEvents as $event) {
            $this->mergeEventIntoCalendarEntries($entries, $event, 'going');
        }

        /** @var list<array<string, mixed>> $entryList */
        $entryList = array_values($entries);

        /** @var list<array<string, mixed>> $sorted */
        $sorted = collect($entryList)
            ->sortBy([
                ['date_key', 'asc'],
                ['role_order', 'asc'],
                ['starts_at_ts', 'asc'],
                ['title', 'asc'],
            ])
            ->values()
            ->all();

        return $sorted;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    #[Computed]
    public function calendarEntriesByDate(): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->calendarEntries();

        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = collect($entries)
            ->groupBy('date_key')
            ->map(fn (Collection $entries): array => $entries->values()->all())
            ->all();

        return $grouped;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function calendarFilters(): array
    {
        $definitions = $this->roleDefinitions();
        $counts = array_fill_keys(array_keys($definitions), 0);
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->calendarEntries();

        foreach ($entries as $entry) {
            $roles = $entry['roles'] ?? [];

            if (! is_array($roles)) {
                continue;
            }

            foreach ($roles as $role) {
                if (! is_string($role) || ! array_key_exists($role, $counts)) {
                    continue;
                }

                $counts[$role]++;
            }
        }

        foreach (array_keys($definitions) as $role) {
            $definitions[$role]['count'] = $counts[$role];
        }

        return $definitions;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function upcomingAgenda(): Collection
    {
        $timezone = UserDateTimeFormatter::resolveTimezone();
        $nowTimestamp = now($timezone)->getTimestamp();
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->calendarEntries();

        /** @var Collection<int, array<string, mixed>> $agenda */
        $agenda = collect($entries)
            ->filter(fn (array $entry): bool => ! ($entry['is_checkin'] ?? false)
                && is_int($entry['starts_at_ts'] ?? null)
                && $entry['starts_at_ts'] >= $nowTimestamp)
            ->sortBy('starts_at_ts')
            ->values();

        return $agenda;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function nextAgendaItem(): ?array
    {
        $upcomingAgenda = $this->upcomingAgenda();

        /** @var array<string, mixed>|null $item */
        $item = $upcomingAgenda->first();

        return $item;
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function paginatedAgenda(): LengthAwarePaginator
    {
        $nextAgendaItem = $this->nextAgendaItem();
        $agendaPreview = $nextAgendaItem
            ? $this->upcomingAgenda()->slice(1)->values()
            : $this->upcomingAgenda();

        return $this->paginateCollection($agendaPreview, self::AGENDA_PER_PAGE, 'agenda_page');
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    #[Computed]
    public function paginatedGoingEvents(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->goingEvents(), self::PLANNER_BUCKET_PER_PAGE, 'going_page');
    }

    /**
     * @return LengthAwarePaginator<int, Institution>
     */
    #[Computed]
    public function paginatedFollowingInstitutions(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->followingInstitutions(), self::PLANNER_BUCKET_PER_PAGE, 'following_institutions_page');
    }

    /**
     * @return LengthAwarePaginator<int, Reference>
     */
    #[Computed]
    public function paginatedFollowingReferences(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->followingReferences(), self::PLANNER_BUCKET_PER_PAGE, 'following_references_page');
    }

    /**
     * @return LengthAwarePaginator<int, Speaker>
     */
    #[Computed]
    public function paginatedFollowingSpeakers(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->followingSpeakers(), self::PLANNER_BUCKET_PER_PAGE, 'following_speakers_page');
    }

    /**
     * @return LengthAwarePaginator<int, Registration>
     */
    #[Computed]
    public function paginatedRegisteredEvents(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->registeredEvents(), self::PLANNER_BUCKET_PER_PAGE, 'registered_page');
    }

    /**
     * @return LengthAwarePaginator<int, Event>
     */
    #[Computed]
    public function paginatedSavedEvents(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->savedEvents(), self::PLANNER_BUCKET_PER_PAGE, 'saved_page');
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    #[Computed]
    public function paginatedSubmittedEvents(): LengthAwarePaginator
    {
        /** @var Collection<int, array<string, mixed>> $submittedEntries */
        $submittedEntries = $this->submittedEvents();

        return $this->paginateCollection($submittedEntries, self::SUBMITTED_PER_PAGE, 'submitted_page');
    }

    /**
     * @return LengthAwarePaginator<int, EventCheckin>
     */
    #[Computed]
    public function paginatedRecentCheckins(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->recentCheckins(), self::CHECKINS_PER_PAGE, 'checkins_page');
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.user-dashboard');
    }

    public function canManageSubmittedEvent(Event $event): bool
    {
        $user = $this->user();

        return $event->userCanManage($user) || $event->submitter_id === $user->id;
    }

    /**
     * @return BelongsToMany<Event, User>
     */
    protected function savedEventsQuery(User $user): BelongsToMany
    {
        return $user->savedEvents()
            ->active()
            ->orderBy('starts_at');
    }

    /**
     * @return BelongsToMany<Event, User>
     */
    protected function goingEventsQuery(User $user): BelongsToMany
    {
        return $user->goingEvents()
            ->active()
            ->orderBy('starts_at');
    }

    /**
     * @return array<int, string>
     */
    protected function plannerEventRelations(): array
    {
        return [
            'media',
            'institution',
            'institution.media',
            'venue',
        ];
    }

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return Collection<int, Event>
     */
    protected function sortEventsForPlanner(Collection $events): Collection
    {
        return $events
            ->sort(fn (Event $left, Event $right): int => $this->comparePlannerDates($left->starts_at, $right->starts_at))
            ->values();
    }

    /**
     * @param  Collection<int, Registration>  $registrations
     * @return Collection<int, Registration>
     */
    protected function sortRegistrationsForPlanner(Collection $registrations): Collection
    {
        return $registrations
            ->sort(function (Registration $left, Registration $right): int {
                $leftDate = $left->event?->starts_at;
                $rightDate = $right->event?->starts_at;

                return $this->comparePlannerDates($leftDate, $rightDate);
            })
            ->values();
    }

    protected function comparePlannerDates(?CarbonInterface $left, ?CarbonInterface $right): int
    {
        [$leftPriority, $leftTimestamp] = $this->plannerDateSortTuple($left);
        [$rightPriority, $rightTimestamp] = $this->plannerDateSortTuple($right);

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        return $leftTimestamp <=> $rightTimestamp;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function plannerDateSortTuple(?CarbonInterface $date): array
    {
        if (! $date instanceof CarbonInterface) {
            return [2, PHP_INT_MAX];
        }

        $timezone = UserDateTimeFormatter::resolveTimezone();
        $localized = $date->copy()->timezone($timezone);

        if ($localized->isPast()) {
            return [1, -1 * $localized->getTimestamp()];
        }

        return [0, $localized->getTimestamp()];
    }

    /**
     * @param  list<string>  $roles
     */
    protected function plannerRolePriority(array $roles): int
    {
        if (in_array('saved', $roles, true)) {
            return 0;
        }

        if (in_array('going', $roles, true)) {
            return 1;
        }

        return 99;
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    protected function mergeEventIntoCalendarEntries(array &$entries, Event $event, string $role): void
    {
        $startsAt = $event->starts_at;
        $dateKey = $this->dateKey($startsAt);

        if ($dateKey === null) {
            return;
        }

        $key = 'event:'.$event->id.':'.$dateKey;

        if (! array_key_exists($key, $entries)) {
            $entries[$key] = $this->makeCalendarEntry(
                key: $key,
                event: $event,
                dateKey: $dateKey,
                startsAt: $startsAt,
                timeLabel: $this->eventTimeLabel($event),
                secondaryLabel: $this->eventLocationLabel($event),
                isCheckin: false,
            );
        }

        $this->appendRoleToCalendarEntry($entries[$key], $role);
    }

    /**
     * @param  array<string, array<string, mixed>>  $entries
     */
    protected function mergeCheckinIntoCalendarEntries(array &$entries, EventCheckin $checkin): void
    {
        $event = $checkin->event;
        $baseDate = $event instanceof Event
            ? $event->starts_at
            : $checkin->checked_in_at;
        $dateKey = $this->dateKey($baseDate);

        if ($dateKey === null) {
            return;
        }

        $key = $event instanceof Event
            ? 'event:'.$event->id.':'.$dateKey
            : 'checkin:'.$checkin->id.':'.$dateKey;

        if (! array_key_exists($key, $entries)) {
            $entries[$key] = $this->makeCalendarEntry(
                key: $key,
                event: $event,
                dateKey: $dateKey,
                startsAt: $baseDate,
                timeLabel: $this->checkinTimeLabel($checkin),
                secondaryLabel: $event instanceof Event ? $this->eventLocationLabel($event) : __('Attendance history'),
                isCheckin: true,
            );
        }

        $entries[$key]['is_checkin'] = true;
        $this->appendRoleToCalendarEntry($entries[$key], 'checkin');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function appendRoleToCalendarEntry(array &$entry, string $role): void
    {
        $definitions = $this->roleDefinitions();

        if (! array_key_exists($role, $definitions)) {
            return;
        }

        /** @var list<string> $roles */
        $roles = $entry['roles'] ?? [];

        if (! in_array($role, $roles, true)) {
            $roles[] = $role;
        }

        usort($roles, fn (string $left, string $right): int => $this->rolePriority($left) <=> $this->rolePriority($right));

        $primaryRole = $roles[0] ?? 'saved';
        $status = is_string($entry['status'] ?? null) ? $entry['status'] : 'approved';

        $entry['roles'] = $roles;
        $entry['primary_role'] = $primaryRole;
        $entry['role_badges'] = array_map(
            fn (string $roleKey): array => [
                'key' => $roleKey,
                'label' => $definitions[$roleKey]['label'],
                'class' => $definitions[$roleKey]['badge_class'],
            ],
            $roles
        );
        $entry['role_order'] = $this->plannerRolePriority($roles);
        $entry['panel_class'] = $this->entryPanelClass($status, $primaryRole, (bool) ($entry['is_checkin'] ?? false));
    }

    /**
     * @return array<string, mixed>
     */
    protected function makeCalendarEntry(
        string $key,
        ?Event $event,
        string $dateKey,
        ?CarbonInterface $startsAt,
        string $timeLabel,
        string $secondaryLabel,
        bool $isCheckin
    ): array {
        $status = $event instanceof Event ? (string) $event->status : 'approved';
        $primaryRole = $isCheckin ? 'checkin' : 'saved';
        $timestamp = $startsAt instanceof CarbonInterface
            ? $startsAt->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->getTimestamp()
            : 0;

        return [
            'key' => $key,
            'id' => $event instanceof Event ? $event->id : $key,
            'title' => $event instanceof Event ? $event->title : __('Checked-in event'),
            'url' => $event instanceof Event ? route('events.show', $event) : null,
            'date_key' => $dateKey,
            'time_label' => $timeLabel,
            'secondary_label' => $secondaryLabel,
            'image_url' => $event instanceof Event ? $event->card_image_url : asset('images/placeholders/event.png'),
            'status' => $status,
            'status_label' => $this->translatedEventWorkflowStatusLabel($status),
            'status_class' => $this->eventStatusClass($status),
            'roles' => [],
            'role_badges' => [],
            'role_order' => $this->plannerRolePriority([$primaryRole]),
            'primary_role' => $primaryRole,
            'panel_class' => $this->entryPanelClass($status, $primaryRole, $isCheckin),
            'starts_at_ts' => $timestamp,
            'is_past' => $startsAt instanceof CarbonInterface && $startsAt->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->isPast(),
            'is_checkin' => $isCheckin,
        ];
    }

    protected function countCalendarEntriesForRole(string $role, bool $futureOnly = false): int
    {
        $timezone = UserDateTimeFormatter::resolveTimezone();
        $nowTimestamp = now($timezone)->getTimestamp();
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->calendarEntries();

        return collect($entries)
            ->filter(function (array $entry) use ($role, $futureOnly, $nowTimestamp): bool {
                $roles = $entry['roles'] ?? [];

                if (! is_array($roles) || ! in_array($role, $roles, true)) {
                    return false;
                }

                if (! $futureOnly) {
                    return true;
                }

                return is_int($entry['starts_at_ts'] ?? null) && $entry['starts_at_ts'] >= $nowTimestamp;
            })
            ->count();
    }

    protected function dateKey(?CarbonInterface $date): ?string
    {
        $key = UserDateTimeFormatter::format($date, 'Y-m-d');

        return $key !== '' ? $key : null;
    }

    protected function eventTimeLabel(Event $event): string
    {
        if (! $event->starts_at instanceof CarbonInterface) {
            return __('Time to be confirmed');
        }

        return UserDateTimeFormatter::translatedFormat($event->starts_at, 'd M')
            .', '.UserDateTimeFormatter::format($event->starts_at, 'h:i A');
    }

    protected function checkinTimeLabel(EventCheckin $checkin): string
    {
        if ($checkin->checked_in_at instanceof CarbonInterface) {
            return __('Checked in :time', ['time' => UserDateTimeFormatter::format($checkin->checked_in_at, 'h:i A')]);
        }

        $event = $checkin->event;

        if ($event instanceof Event && $event->starts_at instanceof CarbonInterface) {
            return UserDateTimeFormatter::translatedFormat($event->starts_at, 'd M')
                .', '.UserDateTimeFormatter::format($event->starts_at, 'h:i A');
        }

        return __('Attendance recorded');
    }

    protected function eventLocationLabel(Event $event): string
    {
        $venue = $event->venue;
        $institution = $event->institution;

        return ($venue ? $venue->name : null)
            ?? ($institution ? $institution->name : null)
            ?? __('Online / TBD');
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function roleDefinitions(): array
    {
        return [
            'saved' => [
                'label' => __('Saved'),
                'badge_class' => 'bg-amber-100 text-amber-700',
                'active_button_class' => 'border-amber-500 bg-amber-500 text-white shadow-sm shadow-amber-500/20',
                'inactive_button_class' => 'border-amber-200 bg-white text-amber-700 hover:border-amber-300',
            ],
            'going' => [
                'label' => __('Going'),
                'badge_class' => 'bg-emerald-100 text-emerald-700',
                'active_button_class' => 'border-emerald-500 bg-emerald-600 text-white shadow-sm shadow-emerald-500/20',
                'inactive_button_class' => 'border-emerald-200 bg-white text-emerald-700 hover:border-emerald-300',
            ],
        ];
    }

    protected function rolePriority(string $role): int
    {
        return match ($role) {
            'saved' => 0,
            'going' => 1,
            default => 99,
        };
    }

    protected function eventStatusClass(string $status): string
    {
        return match ($status) {
            'approved' => 'bg-emerald-100 text-emerald-700',
            'pending', 'needs_changes' => 'bg-amber-100 text-amber-700',
            'cancelled', 'rejected' => 'bg-rose-100 text-rose-700',
            'draft' => 'bg-slate-200 text-slate-700',
            default => 'bg-slate-200 text-slate-700',
        };
    }

    protected function entryPanelClass(string $status, string $primaryRole, bool $isCheckin): string
    {
        if ($isCheckin) {
            return 'border-slate-300 bg-slate-100 text-slate-900 shadow-sm shadow-slate-200/80';
        }

        if (in_array($status, ['cancelled', 'rejected'], true)) {
            return 'border-rose-300 bg-rose-100 text-rose-950 shadow-sm shadow-rose-200/80';
        }

        if (in_array($status, ['pending', 'needs_changes'], true)) {
            return 'border-amber-300 bg-amber-100 text-amber-950 shadow-sm shadow-amber-200/80';
        }

        return match ($primaryRole) {
            'going' => 'border-emerald-300 bg-emerald-100 text-emerald-950 shadow-sm shadow-emerald-200/80',
            'registered' => 'border-sky-300 bg-sky-100 text-sky-950 shadow-sm shadow-sky-200/80',
            'saved' => 'border-amber-300 bg-amber-100 text-amber-950 shadow-sm shadow-amber-200/80',
            'submitted' => 'border-violet-300 bg-violet-100 text-violet-950 shadow-sm shadow-violet-200/80',
            default => 'border-slate-300 bg-slate-100 text-slate-900 shadow-sm shadow-slate-200/80',
        };
    }

    protected function translatedStatusLabel(string $status): string
    {
        $translated = __($status);

        if ($translated !== $status) {
            return $translated;
        }

        return Str::of($status)->replace('_', ' ')->headline()->toString();
    }

    protected function translatedEventWorkflowStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('Menunggu Kelulusan'),
            default => $this->translatedStatusLabel($status),
        };
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @return LengthAwarePaginator<int, TItem>
     */
    protected function paginateCollection(Collection $items, int $perPage, string $pageName): LengthAwarePaginator
    {
        $currentPage = $this->getPage($pageName);

        /** @var Collection<int, TItem> $pageItems */
        $pageItems = $items
            ->forPage($currentPage, $perPage)
            ->values();

        $paginator = new Paginator(
            $pageItems,
            $items->count(),
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'pageName' => $pageName,
            ]
        );

        return $paginator->appends(collect(request()->query())->except($pageName)->all());
    }
}
