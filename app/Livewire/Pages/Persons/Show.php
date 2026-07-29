<?php

namespace App\Livewire\Pages\Persons;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Persons\Enums\AssignmentStatus;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventKeyPersonPivot;
use App\Models\Person;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Person $person;

    public int $upcomingPerPage = 10;

    public int $pastPerPage = 10;

    public bool $isFollowing = false;

    /**
     * @var array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, other: Collection<int, EventKeyPerson>}|null
     */
    private ?array $eventPageData = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Person $person): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless($person->status === 'verified' || $canBypassVisibility, 404);

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
            $user->unfollow($this->person);
            $this->isFollowing = false;

            return;
        }

        $user->follow($this->person);
        $this->isFollowing = true;

        app(ShareTrackingService::class)->recordOutcome(
            type: DawahShareOutcomeType::PersonFollow,
            outcomeKey: 'person_follow:user:'.$user->id.':person:'.$this->person->id,
            subject: $this->person,
            actor: $user,
            request: request(),
            metadata: [
                'person_id' => $this->person->id,
            ],
        );
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

    /**
     * @return EloquentCollection<int, Event>
     */
    public function getUpcomingEventsProperty(): EloquentCollection
    {
        return $this->eventPageData()['upcoming'];
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->eventTotals()['upcoming'];
    }

    /**
     * @return EloquentCollection<int, Event>
     */
    public function getPastEventsProperty(): EloquentCollection
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

    public function render(): View
    {
        $this->loadPersonRelations();

        return view('livewire.pages.persons.show');
    }

    /**
     * @return array{upcoming: EloquentCollection<int, Event>, past: EloquentCollection<int, Event>, other: Collection<int, EventKeyPerson>}
     */
    private function eventPageData(): array
    {
        if ($this->eventPageData !== null) {
            return $this->eventPageData;
        }

        $totals = $this->eventTotals();
        $upcoming = $totals['upcoming'] > 0
            ? $this->personEventQuery()
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at', 'asc')
                ->take($this->upcomingPerPage)
                ->get()
            : new EloquentCollection;
        $past = $totals['past'] > 0
            ? $this->personEventQuery()
                ->where('starts_at', '<', now())
                ->orderBy('starts_at', 'desc')
                ->take($this->pastPerPage)
                ->get()
            : new EloquentCollection;

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
            ->whereHas('event', function ($query): void {
                $query->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->whereNotNull('events.published_at');
            })
            // The profile only renders the event title and role here.
            ->with('event:id,title,slug')
            ->reorder()
            ->orderByRaw("({$primaryOccurrenceSql}) asc nulls last", $primaryOccurrence->getBindings())
            ->orderBy('sort_order')
            ->get();

        $speakerEvents = $upcoming
            ->concat($past)
            ->filter(fn (mixed $event): bool => $event instanceof Event)
            ->unique(fn (Event $event): string => (string) $event->getKey())
            ->values();

        if ($speakerEvents->isNotEmpty()) {
            $speakerEvents->load([
                'references',
                'primaryOccurrence',
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
     * @param  MorphToMany<Address, Model, Pivot, 'pivot'>  $relation
     * @return MorphToMany<Address, Model, Pivot, 'pivot'>
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

    /**
     * @param  HasMany<AddressAreaAssignment, Address>  $relation
     * @return HasMany<AddressAreaAssignment, Address>
     */
    private function joinAreaName(HasMany $relation): HasMany
    {
        $assignmentsTable = (new AddressAreaAssignment)->getTable();
        $areasTable = config('addressing.tables.address_areas', 'address_areas');

        return $relation
            ->addSelect("{$assignmentsTable}.*")
            ->leftJoin($areasTable, "{$assignmentsTable}.address_area_id", '=', "{$areasTable}.id")
            ->addSelect("{$areasTable}.name as hierarchy_area_name");
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
        return once(function (): array {
            $eventsTable = (new Event)->getTable();
            $involvementsTable = (new EventKeyPerson)->getTable();
            $occurrencesTable = (new EventOccurrence)->getTable();
            $now = now();
            $primaryOccurrence = EventOccurrence::query()
                ->select("{$occurrencesTable}.starts_at")
                ->whereColumn("{$occurrencesTable}.event_id", "{$eventsTable}.id")
                ->orderBy("{$occurrencesTable}.starts_at")
                ->orderBy("{$occurrencesTable}.created_at")
                ->orderBy("{$occurrencesTable}.id")
                ->limit(1);

            $totals = Event::query()
                ->join($involvementsTable, "{$eventsTable}.id", '=', "{$involvementsTable}.event_id")
                ->where("{$involvementsTable}.involveable_id", $this->person->getKey())
                ->where("{$involvementsTable}.involveable_type", $this->person->getMorphClass())
                ->where("{$involvementsTable}.role_code", EventKeyPersonRole::Speaker->value)
                ->whereIn("{$eventsTable}.status", Event::PUBLIC_STATUSES)
                ->where("{$eventsTable}.visibility", EventVisibility::Public)
                ->whereNotNull("{$eventsTable}.published_at")
                ->selectRaw(
                    'SUM(CASE WHEN ('.$primaryOccurrence->toSql().') >= ? THEN 1 ELSE 0 END) as upcoming_total, '.
                    'SUM(CASE WHEN ('.$primaryOccurrence->toSql().') < ? THEN 1 ELSE 0 END) as past_total',
                    [$now, $now],
                )
                ->toBase()
                ->first();

            return [
                'upcoming' => (int) ($totals->upcoming_total ?? 0),
                'past' => (int) ($totals->past_total ?? 0),
            ];
        });
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
            ->whereNotNull("{$eventsTable}.published_at");

        return $query;
    }
}
