<?php

namespace App\Features\Persons\EntryPoints\Livewire\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Persons\Enums\AssignmentStatus;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventEngagementToggles;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Events\Api\Data\PublicScheduleRenderedCard;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventSchedulePageData;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Persons\Api\SpeakerStatus;
use App\Features\Persons\Models\Person;
use App\Models\User;
use App\Platform\Location\Ui\Concerns\JoinsHierarchyAreaName;
use App\Shared\Auth\IntendedRedirect;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
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
     * @var array{upcoming: Collection<int, PublicScheduleRenderedCard>, past: Collection<int, PublicScheduleRenderedCard>, other: Collection<int, EventKeyPerson>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}|null
     */
    private ?array $eventPageData = null;

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
            app(SubjectFollowCommands::class)->unfollow($this->person->getMorphClass(), (string) $this->person->getKey(), (string) $user->getKey());
            $this->isFollowing = false;

            return;
        }

        app(SubjectFollowCommands::class)->follow(
            $this->person->getMorphClass(),
            (string) $this->person->getKey(),
            (string) $user->getKey(),
            ['person_id' => $this->person->getKey()],
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

    public function toggleGoing(string $eventId): void
    {
        $loginUrl = app(EventEngagementToggles::class)->toggleGoing($eventId, $this->eventGoingReturnUrl());

        if ($loginUrl !== null) {
            $this->redirect($loginUrl, navigate: true);
        }
    }

    public function toggleSave(string $eventId): void
    {
        $loginUrl = app(EventEngagementToggles::class)->toggleSave($eventId, $this->eventSaveReturnUrl());

        if ($loginUrl !== null) {
            $this->redirect($loginUrl, navigate: true);
        }
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function goingEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(EventEngagement::class)->goingEventIds((string) $user->getKey());
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function savedEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(EventEngagement::class)->savedEventIds((string) $user->getKey());
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

        if (! $from instanceof CarbonInterface || ! $to instanceof CarbonInterface || $from->greaterThan($to)) {
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
     * @return Collection<int, PublicScheduleRenderedCard>
     */
    public function getUpcomingEventsProperty(): Collection
    {
        return $this->eventPageData()['upcoming'];
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->eventPageData()['upcoming_total'];
    }

    public function getUpcomingOverallTotalProperty(): int
    {
        return $this->eventPageData()['upcoming_overall_total'];
    }

    /**
     * @return Collection<int, PublicScheduleRenderedCard>
     */
    public function getPastEventsProperty(): Collection
    {
        return $this->eventPageData()['past'];
    }

    public function getPastTotalProperty(): int
    {
        return $this->eventPageData()['past_total'];
    }

    public function getPastOverallTotalProperty(): int
    {
        return $this->eventPageData()['past_overall_total'];
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
     * @return array{upcoming: Collection<int, PublicScheduleRenderedCard>, past: Collection<int, PublicScheduleRenderedCard>, other: Collection<int, EventKeyPerson>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    private function eventPageData(): array
    {
        if ($this->eventPageData !== null) {
            return $this->eventPageData;
        }

        $range = $this->upcomingDateRange();
        $schedule = app(EventSchedulePageData::class)->forPerson(
            personId: (string) $this->person->getKey(),
            upcomingLimit: $this->upcomingPerPage,
            pastLimit: $this->pastPerPage,
            rangeFrom: $range['from'] ?? null,
            rangeTo: $range['to'] ?? null,
            savedEventIds: $this->savedEventIds(),
            goingEventIds: $this->goingEventIds(),
        );

        $upcoming = collect($schedule['upcoming']);
        $past = collect($schedule['past']);

        $occurrencesTable = (new EventOccurrence)->getTable();
        $involvementsTable = app(EventAuthoring::class)->eventKeyPersonTable();
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

        $roleEvents = new EloquentCollection($otherEvents->all());

        if ($roleEvents->isNotEmpty()) {
            $roleEvents->load([
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

            $roleEvents->load($addressRelations);
        }

        return $this->eventPageData = [
            'upcoming' => $upcoming,
            'past' => $past,
            'other' => $other,
            'upcoming_total' => $schedule['upcoming_total'],
            'past_total' => $schedule['past_total'],
            'upcoming_overall_total' => $schedule['upcoming_overall_total'],
            'past_overall_total' => $schedule['past_overall_total'],
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
     * @return array{from: CarbonInterface, to: CarbonInterface}|null
     */
    private function upcomingDateRange(): ?array
    {
        if ($this->upcomingDateFilter === 'custom') {
            $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
            $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate)?->addDay();

            if (! $from instanceof CarbonInterface || $to === null || $from->greaterThanOrEqualTo($to)) {
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
}
