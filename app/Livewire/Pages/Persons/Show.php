<?php

namespace App\Livewire\Pages\Persons;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\AssignmentStatus;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Data\PublicScheduleLeaf;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Enums\SpeakerStatus;
use App\Livewire\Concerns\JoinsHierarchyAreaName;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventKeyPersonPivot;
use App\Models\Person;
use App\Services\PublicScheduleDiscoveryService;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use App\Support\Timezone\UserDateTimeFormatter;
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
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use JoinsHierarchyAreaName;

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
     * @var array{upcoming: int, past: int}|null
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
            $follow = Follow::query()
                ->where('follower_type', $user->getMorphClass())
                ->where('follower_id', $user->getKey())
                ->where('followable_type', $this->person->getMorphClass())
                ->where('followable_id', $this->person->getKey())
                ->where('status', 'active')
                ->first();

            DB::transaction(function () use ($user, $follow): void {
                $user->unfollow($this->person);

                if ($follow instanceof Follow) {
                    app(ReverseShareOutcomeAction::class)->handle(
                        ShareOutcomeBusinessKey::follow(DawahShareOutcomeType::PersonFollow, (string) $follow->getKey()),
                        'unfollowed',
                        $user,
                        request(),
                    );
                }
            });
            $this->isFollowing = false;

            return;
        }

        DB::transaction(function () use ($user): void {
            $created = $user->follow($this->person);

            // Reactivations reuse the follow row: only rows created by
            // this call freeze a decision, so earlier relationships
            // can never earn later through a new cookie.
            if ($created->wasRecentlyCreated) {
                app(ShareTrackingService::class)->recordOutcome(
                    type: DawahShareOutcomeType::PersonFollow,
                    businessKey: ShareOutcomeBusinessKey::follow(DawahShareOutcomeType::PersonFollow, (string) $created->getKey()),
                    subject: $this->person,
                    actor: $user,
                    request: request(),
                    metadata: [
                        'person_id' => $this->person->getKey(),
                    ],
                    occurredAt: $created->created_at,
                );
            }
        });
        $this->isFollowing = true;
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

        if ($from === null || $to === null || $from->greaterThan($to)) {
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
            ->filter(fn (PublicScheduleLeaf $leaf): bool => $this->isSpeakerForLeaf($leaf));
        $now = now();
        $range = $this->upcomingDateRange();
        $upcoming = $leaves->filter(fn (PublicScheduleLeaf $leaf): bool => $leaf->startsAt() >= $now
            && ($range === null || ($leaf->startsAt() >= $range['from'] && $leaf->startsAt() < $range['to'])))->values();
        $past = $leaves->filter(fn (PublicScheduleLeaf $leaf): bool => $leaf->startsAt() < $now)->reverse()->values();
        $this->eventTotals = ['upcoming' => $upcoming->count(), 'past' => $past->count()];
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
                'primaryOccurrence',
                'displayOccurrence.media',
                'timeExpressions',
                'classifications.term',
            ]);

            /** @var array<string, callable(MorphToMany<Address, Model, Pivot, 'pivot'>|HasMany<AddressAreaAssignment, Address>): mixed> $addressRelations */
            $addressRelations = [
                'institution.addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
                'institution.addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
                'venue.addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
                'venue.addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
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
                'addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
                'addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
            ]);
        });
    }

    /**
     * @return array{upcoming: int, past: int}
     */
    private function eventTotals(): array
    {
        $this->eventPageData();

        return $this->eventTotals ?? ['upcoming' => 0, 'past' => 0];
    }

    private function isSpeakerForLeaf(PublicScheduleLeaf $leaf): bool
    {
        $involvements = $leaf->event->involvements
            ->filter(fn ($involvement): bool => $involvement->role_code === EventKeyPersonRole::Speaker->value
                && in_array($involvement->status, [null, 'active'], true)
                && $involvement->visibility === 'public');
        $session = $leaf->session === null ? collect() : $involvements->where('event_session_id', $leaf->session->getKey());
        $occurrence = $involvements->where('event_occurrence_id', $leaf->occurrence->getKey())->whereNull('event_session_id');
        $event = $involvements->whereNull('event_occurrence_id')->whereNull('event_session_id');
        $effective = $session->isNotEmpty() ? $session : ($occurrence->isNotEmpty() ? $occurrence : $event);

        return $effective->contains(fn ($involvement): bool => $involvement->involveable_type === $this->person->getMorphClass()
            && (string) $involvement->involveable_id === (string) $this->person->getKey());
    }

    /**
     * @return array{from: CarbonInterface, to: CarbonInterface}|null
     */
    private function upcomingDateRange(): ?array
    {
        if ($this->upcomingDateFilter === 'custom') {
            $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
            $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate)?->addDay();

            if ($from === null || $to === null || $from->greaterThanOrEqualTo($to)) {
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

        $query = $this->person->personEvents()
            ->whereIn("{$eventsTable}.status", Event::PUBLIC_STATUSES)
            ->where("{$eventsTable}.visibility", EventVisibility::Public)
            ->whereNotNull("{$eventsTable}.published_at")
            ->whereHas('occurrences');

        return $query;
    }
}
