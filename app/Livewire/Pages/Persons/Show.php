<?php

namespace App\Livewire\Pages\Persons;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\AssignmentStatus;
use App\Actions\Engagement\FollowSubjectAction;
use App\Actions\Engagement\UnfollowSubjectAction;
use App\Data\PublicScheduleLeaf;
use App\Enums\SpeakerStatus;
use App\Livewire\Concerns\JoinsHierarchyAreaName;
use App\Livewire\Concerns\TogglesEventGoing;
use App\Livewire\Concerns\TogglesEventSave;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventKeyPersonPivot;
use App\Models\Person;
use App\Platform\Time\UserDateTimeFormatter;
use App\Services\PublicScheduleDiscoveryService;
use App\Shared\Auth\IntendedRedirect;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Shared\Events\Enums\EventVisibility;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use JoinsHierarchyAreaName;
    use TogglesEventGoing;
    use TogglesEventSave;

    public Person $person;

    public int $upcomingPerPage = 10;

    public int $pastPerPage = 10;

    public string $upcomingDateFilter = 'all';

    public string $customStartDate = '';

    public string $customEndDate = '';

    public bool $showCustomDateRange = false;

    public bool $isFollowing = false;

    /**
     * @var array{upcoming: Collection<int, PublicScheduleLeaf>, past: Collection<int, PublicScheduleLeaf>, other: Collection<int, EventKeyPerson>}|null
     */
    private ?array $eventPageData = null;

    /**
     * @var array{upcoming: int, past: int, upcoming_overall: int, past_overall: int}|null
     */
    private ?array $eventTotals = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Person $person): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless(
            ($canBypassVisibility
                || (in_array((string) $person->status, ['verified', 'pending'], true)
                    && $person->speaker_status === SpeakerStatus::Active)),
            404
        );

        $this->person = $person;
        $this->loadPersonRelations();
        $this->isFollowing = auth()->user()?->isFollowing($person) ?? false;
    }

    public function toggleFollow(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->redirect(
                IntendedRedirect::loginUrl(route('persons.show', $this->person, absolute: false)),
                navigate: true,
            );

            return;
        }

        if ($this->isFollowing) {
            app(UnfollowSubjectAction::class)->handle($this->person, $user);
            $this->isFollowing = false;

            return;
        }

        app(FollowSubjectAction::class)->handle(
            subject: $this->person,
            user: $user,
            metadata: ['person_id' => $this->person->getKey()],
            request: request(),
        );
        $this->isFollowing = true;
    }

    protected function eventSaveReturnUrl(): string
    {
        return route('persons.show', $this->person, absolute: false);
    }

    protected function eventGoingReturnUrl(): string
    {
        return $this->eventSaveReturnUrl();
    }

    public function loadMoreUpcoming(): void
    {
        $this->upcomingPerPage += 10;
        $this->eventPageData = null;
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 10;
        $this->eventPageData = null;
    }

    public function filterUpcomingEvents(): void
    {
        $this->upcomingPerPage = 10;
        $this->eventPageData = null;
        $this->eventTotals = null;
    }

    public function updatedUpcomingDateFilter(): void
    {
        if ($this->upcomingDateFilter === 'custom') {
            $this->resetValidation('customDateRange');

            return;
        }

        $this->filterUpcomingEvents();
    }

    public function applyCustomDateRange(): void
    {
        $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
        $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate);

        if (!$from instanceof \Carbon\CarbonInterface || !$to instanceof \Carbon\CarbonInterface || $from->greaterThan($to)) {
            $this->addError('customDateRange', __('Sila pilih julat tarikh yang sah.'));

            return;
        }

        $this->resetValidation('customDateRange');
        $this->upcomingDateFilter = 'custom';
        $this->showCustomDateRange = false;
        $this->filterUpcomingEvents();
    }

    public function clearUpcomingDateFilter(): void
    {
        $this->upcomingDateFilter = 'all';
        $this->customStartDate = '';
        $this->customEndDate = '';
        $this->showCustomDateRange = false;
        $this->resetValidation('customDateRange');
        $this->filterUpcomingEvents();
    }

    /**
     * @return Collection<int, PublicScheduleLeaf>
     */
    public function getUpcomingEventsProperty(): Collection
    {
        return $this->eventPageData()['upcoming'];
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->eventTotals()['upcoming'];
    }

    public function getUpcomingOverallTotalProperty(): int
    {
        return $this->eventTotals()['upcoming_overall'];
    }

    /**
     * @return Collection<int, PublicScheduleLeaf>
     */
    public function getPastEventsProperty(): Collection
    {
        return $this->eventPageData()['past'];
    }

    public function getPastTotalProperty(): int
    {
        return $this->eventTotals()['past'];
    }

    public function getPastOverallTotalProperty(): int
    {
        return $this->eventTotals()['past_overall'];
    }

    /**
     * @return Collection<int, EventKeyPerson>
     */
    public function getOtherRoleParticipationsProperty(): Collection
    {
        return $this->eventPageData()['other'];
    }

    public function getHasAdminOrOwnerMemberProperty(): bool
    {
        return OwnerContext::withOwner(null, fn (): bool => $this->person->members()
            ->wherePivotIn('role', [
                MemberRole::Admin->value,
                MemberRole::Owner->value,
            ])
            ->exists());
    }

    public function render(): View
    {
        $this->loadPersonRelations();

        return view('livewire.pages.persons.show');
    }

    /**
     * @return array{upcoming: Collection<int, PublicScheduleLeaf>, past: Collection<int, PublicScheduleLeaf>, other: Collection<int, EventKeyPerson>}
     */
    private function eventPageData(): array
    {
        if ($this->eventPageData !== null) {
            return $this->eventPageData;
        }

        $events = $this->personEventQuery()->reorder()->with('involvements')->get()->unique('id');
        $leaves = app(PublicScheduleDiscoveryService::class)->leavesForEvents($events)
            ->map(fn (PublicScheduleLeaf $leaf): ?PublicScheduleLeaf => $this->speakerLeafFor($leaf))
            ->filter()
            ->values();
        $now = now();
        $range = $this->upcomingDateRange();
        $endInstant = static fn (PublicScheduleLeaf $leaf): ?CarbonInterface => $leaf->endsAt() ?? $leaf->startsAt();
        $upcomingOverall = $leaves->filter(fn (PublicScheduleLeaf $leaf): bool => ($endInstant($leaf)?->greaterThanOrEqualTo($now)) ?? false)->values();
        $upcoming = $upcomingOverall->filter(fn (PublicScheduleLeaf $leaf): bool => $range === null
            || ($leaf->startsAt() >= $range['from'] && $leaf->startsAt() < $range['to']))->values();
        $past = $leaves->filter(fn (PublicScheduleLeaf $leaf): bool => ($endInstant($leaf)?->lessThan($now)) ?? false)->reverse()->values();
        $this->eventTotals = [
            'upcoming' => $upcoming->count(),
            'past' => $past->count(),
            'upcoming_overall' => $upcomingOverall->count(),
            'past_overall' => $past->count(),
        ];
        $upcoming = $upcoming->take($this->upcomingPerPage);
        $past = $past->take($this->pastPerPage);

        $occurrencesTable = (new EventOccurrence)->getTable();
        $involvementsTable = (new EventKeyPerson)->getTable();
        $primaryOccurrence = EventOccurrence::query()
            ->select("{$occurrencesTable}.starts_at")
            ->whereColumn("{$occurrencesTable}.event_id", "{$involvementsTable}.event_id")
            ->orderBy("{$occurrencesTable}.starts_at")
            ->orderBy("{$occurrencesTable}.created_at")
            ->orderBy("{$occurrencesTable}.id")
            ->limit(1);
        $primaryOccurrenceSql = $primaryOccurrence->toSql();

        $other = $this->person->nonSpeakerEventKeyPeople()
            ->whereHas('event', function (Builder $query): void {
                $query->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->whereNotNull('events.published_at')
                    ->whereHas('occurrences');
            })
            ->with('event')
            ->reorder()
            ->orderByRaw("({$primaryOccurrenceSql}) asc nulls last", $primaryOccurrence->getBindings())
            ->orderBy('sort_order')
            ->get();

        $otherEvents = $other
            ->map(fn (EventKeyPerson $keyPerson): mixed => $keyPerson->event)
            ->filter(fn (mixed $event): bool => $event instanceof Event)
            ->unique(fn (Event $event): string => (string) $event->getKey())
            ->values();

        $speakerEvents = new EloquentCollection($upcoming
            ->concat($past)
            ->map(fn (PublicScheduleLeaf $leaf): Event => $leaf->event)
            ->concat($otherEvents)
            ->filter(fn (mixed $event): bool => $event instanceof Event)
            ->unique(fn (Event $event): string => (string) $event->getKey())
            ->values()->all());

        if ($speakerEvents->isNotEmpty()) {
            $speakerEvents->load([
                'references' => fn ($query) => $query->active(),
                'media',
                'institution.media',
                'persons.media',
                'persons.titleAssignments' => function (MorphMany $relation): void {
                    $relation->where('status', AssignmentStatus::Active);
                    $relation->with('title.category');
                },
                'primaryOccurrence',
                'displayOccurrence.media',
                'timeExpressions',
                'classifications.term',
            ]);

            /** @var array<string, callable(MorphToMany<Address, Model, Pivot, 'pivot'>|HasMany<AddressAreaAssignment, Address>): mixed> $addressRelations */
            $addressRelations = [
                'institution.addresses' => $this->joinAddressStateName(...),
                'institution.addresses.areaAssignments' => $this->joinAreaName(...),
                'venue.addresses' => $this->joinAddressStateName(...),
                'venue.addresses.areaAssignments' => $this->joinAreaName(...),
            ];

            $speakerEvents->load($addressRelations);
        }

        return $this->eventPageData = [
            'upcoming' => $upcoming,
            'past' => $past,
            'other' => $other,
        ];
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  MorphToMany<Address, TDeclaringModel, MorphPivot, 'pivot'>  $relation
     * @return MorphToMany<Address, TDeclaringModel, MorphPivot, 'pivot'>
     */
    private function joinAddressStateName(MorphToMany $relation): MorphToMany
    {
        $addressesTable = (new Address)->getTable();
        $statesTable = (new State)->getTable();

        return $relation
            ->addSelect("{$addressesTable}.*")
            ->leftJoin($statesTable, "{$addressesTable}.state_id", '=', "{$statesTable}.id")
            ->addSelect("{$statesTable}.name as hierarchy_state_name");
    }

    private function loadPersonRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->person->loadMissing([
                'media',
                'contactMethods',
                'socialProfiles',
                'titleAssignments' => function (MorphMany $relation): void {
                    $relation->where('status', AssignmentStatus::Active);
                    $relation->with('title.category');
                },
                'addresses' => $this->joinAddressStateName(...),
                'addresses.areaAssignments' => $this->joinAreaName(...),
            ]);
        });
    }

    /**
     * @return array{upcoming: int, past: int, upcoming_overall: int, past_overall: int}
     */
    private function eventTotals(): array
    {
        $this->eventPageData();

        return $this->eventTotals ?? [
            'upcoming' => 0,
            'past' => 0,
            'upcoming_overall' => 0,
            'past_overall' => 0,
        ];
    }

    private function speakerLeafFor(PublicScheduleLeaf $leaf): ?PublicScheduleLeaf
    {
        $involvements = $leaf->event->involvements
            ->filter(fn ($involvement): bool => $involvement->role_code === EventKeyPersonRole::Speaker->value
                && in_array($involvement->status, [null, 'active'], true)
                && $involvement->visibility === 'public');

        $matchesPerson = fn ($involvement): bool => $involvement->involveable_type === $this->person->getMorphClass()
            && (string) $involvement->involveable_id === (string) $this->person->getKey();

        $sessions = $leaf->sessions
            ->filter(fn ($session): bool => $involvements
                ->where('event_session_id', $session->getKey())
                ->contains($matchesPerson))
            ->values();

        if ($sessions->isNotEmpty()) {
            return new PublicScheduleLeaf($leaf->event, $leaf->occurrence, $sessions);
        }

        $occurrence = $involvements->where('event_occurrence_id', $leaf->occurrence->getKey())->whereNull('event_session_id');
        $event = $involvements->whereNull('event_occurrence_id')->whereNull('event_session_id');

        return $occurrence->contains($matchesPerson) || $event->contains($matchesPerson)
            ? $leaf
            : null;
    }

    /**
     * @return array{from: CarbonInterface, to: CarbonInterface}|null
     */
    private function upcomingDateRange(): ?array
    {
        if ($this->upcomingDateFilter === 'custom') {
            $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
            $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate)?->addDay();

            if (!$from instanceof \Carbon\CarbonInterface || $to === null || $from->greaterThanOrEqualTo($to)) {
                return null;
            }

            return [
                'from' => $from,
                'to' => $to,
            ];
        }

        $today = UserDateTimeFormatter::userNow()->startOfDay();

        [$from, $to] = match ($this->upcomingDateFilter) {
            'today' => [$today, $today->copy()->addDay()],
            'tomorrow' => [$today->copy()->addDay(), $today->copy()->addDays(2)],
            'this_week' => [$today, $today->copy()->startOfWeek()->addWeek()],
            'this_weekend' => $this->weekendDateRange($today),
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth()],
            'next_week' => [
                $today->copy()->startOfWeek()->addWeek(),
                $today->copy()->startOfWeek()->addWeeks(2),
            ],
            'next_month' => [
                $today->copy()->startOfMonth()->addMonth(),
                $today->copy()->startOfMonth()->addMonths(2),
            ],
            default => [null, null],
        };

        if ($from === null || $to === null) {
            return null;
        }

        return [
            'from' => $from->utc(),
            'to' => $to->utc(),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function weekendDateRange(CarbonInterface $today): array
    {
        // On Sunday the weekend began yesterday: anchor on Saturday so the
        // window stays Sat + Sun instead of drifting into Monday.
        $weekendStart = $today->isSunday()
            ? $today->copy()->subDay()
            : ($today->isSaturday() ? $today->copy() : $today->copy()->next(Carbon::SATURDAY));

        return [$weekendStart, $weekendStart->copy()->addDays(2)->startOfDay()];
    }

    /**
     * @return BelongsToMany<Event, Person, EventKeyPersonPivot, 'pivot'>
     */
    private function personEventQuery(): BelongsToMany
    {
        $eventsTable = (new Event)->getTable();

        return $this->person->personEvents()
            ->whereIn("{$eventsTable}.status", Event::PUBLIC_STATUSES)
            ->where("{$eventsTable}.visibility", EventVisibility::Public)
            ->whereNotNull("{$eventsTable}.published_at")
            ->whereHas('occurrences');
    }
}
