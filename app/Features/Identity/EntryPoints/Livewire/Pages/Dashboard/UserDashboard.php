<?php

namespace App\Features\Identity\EntryPoints\Livewire\Pages\Dashboard;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\AccountEventFacts;
use App\Features\Events\Api\Data\EventSubmissionCard;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Api\Data\PlannerEventCard;
use App\Features\Institutions\Api\InstitutionDirectoryRecord;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonDirectoryRecord;
use App\Features\Persons\Api\PersonLookup;
use App\Features\References\Api\ReferenceLookup;
use App\Features\References\Api\ReferenceSummary;
use App\Features\Registration\Api\Data\CheckInSnapshot;
use App\Features\Registration\Api\Data\RegistrationSnapshot;
use App\Features\Registration\Api\RegistrationLookup;
use App\Features\Rewards\Api\MemberEnrollment;
use App\Features\Rewards\Api\PublishedProgramVersions;
use App\Features\SavedSearches\Api\SavedSearchReader;
use App\Features\SavedSearches\Api\SavedSearchSnapshot;
use App\Features\ShareTracking\Api\ShareTrackingAnalytics;
use App\Features\ShareTracking\Api\SourceReceiptReader;
use App\Models\User;
use App\Platform\Time\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
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

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

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
     *     distinct_browsers: int,
     *     signups: int,
     *     event_registrations: int,
     *     total_outcomes: int
     * }
     */
    #[Computed]
    public function dakwahSummary(): array
    {
        return app(ShareTrackingAnalytics::class)->summaryForAccount($this->user());
    }

    #[Computed]
    public function shareRewardEnrollmentPrompt(): ?RewardProgram
    {
        $user = $this->user();
        $programs = RewardProgram::query()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('status', RewardProgramStatus::Active->value)
            ->orderBy('title')
            ->orderBy('id')
            ->get();
        $publishedVersions = app(PublishedProgramVersions::class);

        foreach ($programs as $program) {
            if ($user->cannot('enroll', $program)) {
                continue;
            }

            $eligibility = $publishedVersions->publishedVersion((string) $program->getKey())?->eligibility;

            if (! is_array($eligibility) || ($eligibility['require_enrollment'] ?? false) !== true) {
                continue;
            }

            $outcomes = $eligibility['outcomes'] ?? [];

            if (! is_array($outcomes) || ! $this->hasBankedRewardOutcome($user, $outcomes)) {
                continue;
            }

            if (RewardEnrollment::query()
                ->where('program_id', $program->getKey())
                ->where('member_type', $user->getMorphClass())
                ->where('member_id', $user->getKey())
                ->where('status', 'active')
                ->exists()) {
                continue;
            }

            return $program;
        }

        return null;
    }

    public function enrollRewardProgram(): void
    {
        $program = $this->shareRewardEnrollmentPrompt();

        if (! $program instanceof RewardProgram) {
            return;
        }

        $user = $this->user();

        app(MemberEnrollment::class)->enroll(
            (string) $program->getKey(),
            (string) $user->getKey(),
            $user->getMorphClass(),
            null,
            'dashboard-prompt',
            true,
            'member-portal',
        );
    }

    /**
     * @return Collection<int, PlannerEventCard>
     */
    #[Computed]
    public function savedEvents(): Collection
    {
        $ids = app(EventEngagement::class)->savedEventIds((string) $this->user()->getKey());

        /** @var Collection<int, PlannerEventCard> $events */
        $events = $ids === []
            ? new Collection
            : collect(app(EventScheduleReads::class)->plannerEventCardsForIds($ids, true));

        return $this->sortEventsForPlanner($events);
    }

    /**
     * @return Collection<int, PlannerEventCard>
     */
    #[Computed]
    public function goingEvents(): Collection
    {
        $ids = app(EventEngagement::class)->goingEventIds((string) $this->user()->getKey());

        /** @var Collection<int, PlannerEventCard> $events */
        $events = $ids === []
            ? new Collection
            : collect(app(EventScheduleReads::class)->plannerEventCardsForIds($ids, false));

        return $this->sortEventsForPlanner($events);
    }

    /**
     * @return Collection<int, InstitutionDirectoryRecord>
     */
    #[Computed]
    public function followingInstitutions(): Collection
    {
        $user = $this->user();
        $ids = app(SubjectFollows::class)->followedSubjectIds((string) $user->getKey(), EngagementSubjectType::Institution);

        /** @var Collection<int, InstitutionDirectoryRecord> $institutions */
        /** @var Collection<int, InstitutionDirectoryRecord> $institutions */
        $institutions = $ids === []
            ? new Collection
            : collect(app(InstitutionLookup::class)->followedInstitutions($ids));

        return $institutions;
    }

    /**
     * @return Collection<int, ReferenceSummary>
     */
    #[Computed]
    public function followingReferences(): Collection
    {
        $user = $this->user();
        $ids = app(SubjectFollows::class)->followedSubjectIds((string) $user->getKey(), EngagementSubjectType::Reference);

        /** @var Collection<int, ReferenceSummary> $references */
        /** @var Collection<int, ReferenceSummary> $references */
        $references = $ids === []
            ? new Collection
            : collect(app(ReferenceLookup::class)->followedReferences($ids));

        return $references;
    }

    /**
     * @return Collection<int, PersonDirectoryRecord>
     */
    #[Computed]
    public function followingPersons(): Collection
    {
        $user = $this->user();
        $ids = app(SubjectFollows::class)->followedSubjectIds((string) $user->getKey(), EngagementSubjectType::Person);

        /** @var Collection<int, PersonDirectoryRecord> $persons */
        /** @var Collection<int, PersonDirectoryRecord> $persons */
        $persons = $ids === []
            ? new Collection
            : collect(app(PersonLookup::class)->followedPersons($ids));

        return $persons;
    }

    /**
     * @return Collection<int, SavedSearchSnapshot>
     */
    #[Computed]
    public function recentSavedSearches(): Collection
    {
        $user = $this->user();

        return collect(app(SavedSearchReader::class)->forOwner($user->getMorphClass(), (string) $user->getKey(), 3));
    }

    #[Computed]
    public function recommendedEvent(): ?PlannerEventCard
    {
        /** @var list<string> $excludedEventIds */
        $excludedEventIds = $this->savedEvents()
            ->pluck('id')
            ->merge($this->goingEvents()->pluck('id'))
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        /** @var ?PlannerEventCard $event */
        $event = app(EventScheduleReads::class)->recommendedPlannerEventCard(
            $excludedEventIds,
            now('UTC')->toIso8601String(),
        );

        return $event;
    }

    /**
     * @return Collection<int, array{event: PlannerEventCard, roles: list<string>}>
     */
    #[Computed]
    public function majlisCards(): Collection
    {
        /** @var Collection<string, array{event: PlannerEventCard, roles: list<string>}> $cards */
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

        /** @var Collection<int, array{event: PlannerEventCard, roles: list<string>}> $sorted */
        $sorted = $cards
            ->values()
            ->sortBy(fn (array $card): int => $card['event']->startsAt?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        return $sorted;
    }

    /**
     * @return LengthAwarePaginator<int, array{event: PlannerEventCard, roles: list<string>}>
     */
    #[Computed]
    public function paginatedMajlisCards(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->majlisCards(), self::MAJLIS_CARD_PER_PAGE, 'majlis_page');
    }

    /** @return Collection<int, RegistrationSnapshot> */
    #[Computed]
    public function registeredEvents(): Collection
    {
        $ids = app(AccountEventFacts::class)->registrationIds((string) $this->user()->getKey());
        $registrations = $ids === [] ? collect() : collect(app(RegistrationLookup::class)->plannerRegistrationsForIds($ids));

        return $this->sortRegistrationsForPlanner($registrations->filter(
            static fn (RegistrationSnapshot $registration): bool => $registration->eventTitle !== null,
        )->values());
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function submittedEvents(): Collection
    {
        $submissions = app(EventSubmissions::class)->plannerSubmissionsForAccount((string) $this->user()->getKey());
        $entries = collect();

        foreach ($submissions as $submission) {
            if (! $submission instanceof PlannerEventCardSubmissionCard || $submission->eventId === '') {
                continue;
            }

            $entries->push([
                'event' => [
                    'id' => $submission->eventId,
                    'title' => $submission->eventTitle,
                    'url' => $submission->eventUrl,
                ],
                'created_at' => $submission->createdAt,
                'notes' => $submission->notes,
            ]);
        }

        return $entries->sortByDesc(static fn (array $entry): int => is_string($entry['created_at']) ? (strtotime($entry['created_at']) ?: 0) : 0)->values();
    }

    /** @return Collection<int, CheckInSnapshot> */
    #[Computed]
    public function recentCheckins(): Collection
    {
        return collect(app(RegistrationLookup::class)->plannerCheckInsForAccount((string) $this->user()->getKey()));
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
     * @return LengthAwarePaginator<int, PlannerEventCard>
     */
    #[Computed]
    public function paginatedGoingEvents(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->goingEvents(), self::PLANNER_BUCKET_PER_PAGE, 'going_page');
    }

    /**
     * @return LengthAwarePaginator<int, InstitutionDirectoryRecord>
     */
    #[Computed]
    public function paginatedFollowingInstitutions(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->followingInstitutions(), self::PLANNER_BUCKET_PER_PAGE, 'following_institutions_page');
    }

    /**
     * @return LengthAwarePaginator<int, ReferenceSummary>
     */
    #[Computed]
    public function paginatedFollowingReferences(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->followingReferences(), self::PLANNER_BUCKET_PER_PAGE, 'following_references_page');
    }

    /**
     * @return LengthAwarePaginator<int, PersonDirectoryRecord>
     */
    #[Computed]
    public function paginatedFollowingPersons(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->followingPersons(), self::PLANNER_BUCKET_PER_PAGE, 'following_persons_page');
    }

    /**
     * @return LengthAwarePaginator<int, RegistrationSnapshot>
     */
    #[Computed]
    public function paginatedRegisteredEvents(): LengthAwarePaginator
    {
        return $this->paginateCollection($this->registeredEvents(), self::PLANNER_BUCKET_PER_PAGE, 'registered_page');
    }

    /**
     * @return LengthAwarePaginator<int, PlannerEventCard>
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
     * @return LengthAwarePaginator<int, CheckInSnapshot>
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

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  Collection<int, PlannerEventCard>  $events
     * @return Collection<int, PlannerEventCard>
     */
    protected function sortEventsForPlanner(Collection $events): Collection
    {
        return $events
            ->sort(fn (PlannerEventCard $left, PlannerEventCard $right): int => $this->comparePlannerDates($left->startsAt, $right->startsAt))
            ->values();
    }

    /**
     * @param  Collection<int, RegistrationSnapshot>  $registrations
     * @return Collection<int, RegistrationSnapshot>
     */
    protected function sortRegistrationsForPlanner(Collection $registrations): Collection
    {
        return $registrations->sort(function (RegistrationSnapshot $left, RegistrationSnapshot $right): int {
            $leftDate = $left->eventStartsAt !== null ? \Carbon\CarbonImmutable::parse($left->eventStartsAt) : null;
            $rightDate = $right->eventStartsAt !== null ? \Carbon\CarbonImmutable::parse($right->eventStartsAt) : null;

            return $this->comparePlannerDates($leftDate, $rightDate);
        })->values();
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
    protected function mergeEventIntoCalendarEntries(array &$entries, PlannerEventCard $event, string $role): void
    {
        $startsAt = $event->startsAt;
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
        ?PlannerEventCard $event,
        string $dateKey,
        ?CarbonInterface $startsAt,
        string $timeLabel,
        string $secondaryLabel,
        bool $isCheckin
    ): array {
        $status = $event instanceof PlannerEventCard ? (string) $event->status : 'approved';
        $primaryRole = $isCheckin ? 'checkin' : 'saved';
        $timestamp = $startsAt instanceof CarbonInterface
            ? $startsAt->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->getTimestamp()
            : 0;

        return [
            'key' => $key,
            'id' => $event instanceof PlannerEventCard ? $event->id : $key,
            'title' => $event instanceof PlannerEventCard ? $event->title : __('Checked-in event'),
            'url' => $event instanceof PlannerEventCard ? $event->url : null,
            'date_key' => $dateKey,
            'time_label' => $timeLabel,
            'secondary_label' => $secondaryLabel,
            'image_url' => $event instanceof PlannerEventCard ? $event->imageUrl : asset('images/placeholders/event-wide-v1.jpg'),
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

    protected function eventTimeLabel(PlannerEventCard $event): string
    {
        if (! $event->startsAt instanceof CarbonInterface) {
            return __('Time to be confirmed');
        }

        return UserDateTimeFormatter::translatedFormat($event->startsAt, 'd M')
            .', '.UserDateTimeFormatter::format($event->startsAt, 'h:i A');
    }

    protected function eventLocationLabel(PlannerEventCard $event): string
    {
        return $event->locationName ?? __('Online / TBD');
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

    /**
     * @param  list<mixed>  $outcomes
     */
    private function hasBankedRewardOutcome(User $user, array $outcomes): bool
    {
        $summary = app(ShareTrackingAnalytics::class)->summaryForAccount($user);

        if (in_array('signup', $outcomes, true) && $summary['signups'] > 0) {
            return true;
        }

        if (in_array('event_registration', $outcomes, true)) {
            if ($summary['event_registrations'] > 0) {
                return true;
            }

            $registrationTransitions = collect(app(RegistrationLookup::class)->confirmedRegistrationIdsForAccount((string) $user->getKey()))
                ->map(static fn (string $id): string => 'registration:'.$id)
                ->all();

            if ($registrationTransitions !== [] && app(SourceReceiptReader::class)->hasForSourceTransitions('event_registration', $registrationTransitions)) {
                return true;
            }
        }

        if (! in_array('event_checkin', $outcomes, true)) {
            return false;
        }

        $checkinTransitions = collect(app(RegistrationLookup::class)->checkInTransitionIdsForAccount((string) $user->getKey()))
            ->map(static fn (string $id): string => 'checkin:'.$id)
            ->all();

        return $checkinTransitions !== [] && app(SourceReceiptReader::class)->hasForSourceTransitions('event_checkin', $checkinTransitions);
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
