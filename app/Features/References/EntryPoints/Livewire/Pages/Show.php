<?php

namespace App\Features\References\EntryPoints\Livewire\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use App\Features\Discovery\Api\PublicScheduleLeaf as PublicScheduleLeafContract;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventEngagementToggles;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Events\Api\EventSchedulePageData;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventReferencePivot;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\Location\Ui\Concerns\JoinsHierarchyAreaName;
use App\Platform\Time\UserDateTimeFormatter;
use App\Shared\Auth\IntendedRedirect;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public Reference $reference;

    public int $upcomingPerPage = 6;

    public int $pastPerPage = 6;

    public bool $isFollowing = false;

    public string $upcomingDateFilter = 'all';

    public string $customStartDate = '';

    public string $customEndDate = '';

    public bool $showCustomDateRange = false;

    /**
     * @var array{upcoming: Collection<int, PublicScheduleLeafContract>, past: Collection<int, PublicScheduleLeafContract>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}|null
     */
    private ?array $schedulePageData = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Reference $reference): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless(
            $reference->isPubliclyVisible() || $canBypassVisibility,
            404,
        );

        $this->reference = $reference;
        $this->loadReferenceRelations();
        $this->isFollowing = auth()->user()?->isFollowing($reference) ?? false;
    }

    protected function eventSaveReturnUrl(): string
    {
        return route('references.show', $this->reference, absolute: false);
    }

    protected function eventGoingReturnUrl(): string
    {
        return $this->eventSaveReturnUrl();
    }

    public function toggleFollow(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->redirect(
                IntendedRedirect::loginUrl(route('references.show', $this->reference, absolute: false)),
                navigate: true,
            );

            return;
        }

        if ($this->isFollowing) {
            app(SubjectFollowCommands::class)->unfollow($this->reference->getMorphClass(), (string) $this->reference->getKey(), (string) $user->getKey());
            $this->isFollowing = false;

            return;
        }

        app(SubjectFollowCommands::class)->follow(
            $this->reference->getMorphClass(),
            (string) $this->reference->getKey(),
            (string) $user->getKey(),
            ['reference_id' => $this->reference->getKey()],
        );
        $this->isFollowing = true;
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

    public function filterUpcomingEvents(): void
    {
        $this->schedulePageData = null;
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

    public function loadMoreUpcoming(): void
    {
        $this->upcomingPerPage += 6;
        $this->schedulePageData = null;
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 6;
        $this->schedulePageData = null;
    }

    /**
     * @return Collection<int, PublicScheduleLeafContract>
     */
    public function getUpcomingEventsProperty(): Collection
    {
        return $this->schedulePageData()['upcoming'];
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->schedulePageData()['upcoming_total'];
    }

    /**
     * @return Collection<int, PublicScheduleLeafContract>
     */
    public function getPastEventsProperty(): Collection
    {
        return $this->schedulePageData()['past'];
    }

    public function getPastTotalProperty(): int
    {
        return $this->schedulePageData()['past_total'];
    }

    public function getUpcomingOverallTotalProperty(): int
    {
        return $this->schedulePageData()['upcoming_overall_total'];
    }

    public function getPastOverallTotalProperty(): int
    {
        return $this->schedulePageData()['past_overall_total'];
    }

    private function eventQuery(): EventBuilder
    {
        /** @var array<string, string|\Closure> $addressRelations */
        $addressRelations = [
            'venue.media',
            'venue.addresses' => $this->joinAddressStateName(...),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => $this->joinAreaName(...),
        ];

        $referenceIds = app(ReferenceQueries::class)->defaultEventReferenceIds((string) $this->reference->getKey());
        /** @var EventBuilder $query */
        $query = Event::query()->where(function (Builder $referenceFilter) use ($referenceIds): void {
            $referenceFilter
                ->whereHas('references', fn (Builder $references): Builder => $references
                    ->whereIn('references.id', $referenceIds))
                ->orWhereHas('occurrences', function (Builder $occurrences) use ($referenceIds): void {
                    $occurrence = new EventOccurrence;
                    $occurrences
                        ->whereIn($occurrence->qualifyColumn('status'), Event::PUBLIC_SCHEDULE_STATUSES)
                        ->whereIn($occurrence->qualifyColumn('visibility'), Event::PUBLIC_SCHEDULE_VISIBILITIES)
                        ->whereHas('references', function (Builder $referenceRows) use ($referenceIds): void {
                            $referenceRows
                                ->where('visibility', 'public')
                                ->whereHasMorph('referenceable', [app(ReferenceQueries::class)->referenceModelClass()], function (Builder $references) use ($referenceIds): void {
                                    app(ReferenceQueries::class)->applyPublicVisibility($references)
                                        ->whereIn('references.id', $referenceIds);
                                });
                        });
                });
        });

        return $query
            ->active()
            ->reorder()
            ->with([
                ...$addressRelations,
                'persons.media',
                'persons.titleAssignments.title.category',
                'keyPeople.person',
                'references' => /** @param BelongsToMany<Reference, Event, EventReferencePivot, 'pivot'> $references */ function (BelongsToMany $references): void {
                    app(ReferenceQueries::class)->applyPublicVisibility($references->getQuery());
                },
                'media',
                'institution.media',
                'classifications.term',
                'primaryOccurrence',
                'displayOccurrence.media',
                'timeExpressions',
                'links',
                'occurrences.references',
            ]);
    }

    /**
     * @param  Collection<int, PublicScheduleLeafContract>  $leaves
     * @return Collection<int, PublicScheduleLeafContract>
     */
    protected function filterScheduleLeaves(Collection $leaves): Collection
    {
        $referenceIds = app(ReferenceQueries::class)->defaultEventReferenceIds((string) $this->reference->getKey());

        return $leaves
            ->map(function (PublicScheduleLeafContract $leaf) use ($referenceIds): ?PublicScheduleLeafContract {
                $event = $leaf->event();
                $occurrence = $leaf->occurrence();

                if (! $event instanceof Event || ! $occurrence instanceof EventOccurrence) {
                    return null;
                }

                if ($event->references->contains(fn (Reference $reference): bool => in_array((string) $reference->getKey(), $referenceIds, true))) {
                    return $leaf;
                }

                $occurrenceMatches = $occurrence->references->contains(fn ($reference): bool => $reference->visibility === 'public'
                    && $reference->referenceable_type === $this->reference->getMorphClass()
                    && in_array((string) $reference->referenceable_id, $referenceIds, true)
                    && $reference->event_session_id === null);

                if ($occurrenceMatches) {
                    return $leaf;
                }

                $sessions = collect($leaf->sessions())->filter(fn ($session): bool => $occurrence->references->contains(fn ($reference): bool => $reference->visibility === 'public'
                    && $reference->referenceable_type === $this->reference->getMorphClass()
                    && in_array((string) $reference->referenceable_id, $referenceIds, true)
                    && $reference->event_session_id === $session->getKey()))->values();

                return $sessions->isEmpty()
                    ? null
                    : $leaf->withSessions($sessions->map(static fn ($session): string => (string) $session->getKey())->all());
            })
            ->filter()
            ->values();
    }

    /** @return Builder<Event> */
    protected function eventPageBaseQuery(): Builder
    {
        return $this->eventQuery();
    }

    /**
     * @return array{upcoming: Collection<int, PublicScheduleLeafContract>, past: Collection<int, PublicScheduleLeafContract>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    private function schedulePageData(): array
    {
        if ($this->schedulePageData !== null) {
            return $this->schedulePageData;
        }

        $range = $this->upcomingDateRange();

        /** @var array{upcoming: Collection<int, PublicScheduleLeafContract>, past: Collection<int, PublicScheduleLeafContract>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int} $data */
        $data = app(EventSchedulePageData::class)->forQuery(
            $this->eventPageBaseQuery(),
            $this->upcomingPerPage,
            $this->pastPerPage,
            $range['from'] ?? null,
            $range['to'] ?? null,
            $this->filterScheduleLeaves(...),
        );

        return $this->schedulePageData = $data;
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

    public function render(): View
    {
        $this->loadReferenceRelations();

        return view('livewire.pages.references.show');
    }

    private function loadReferenceRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->reference->load([
                'media',
                'socialProfiles',
                'authors.titleAssignments.title.category',
                'parentReference.authors.titleAssignments.title.category',
                'parentReference.parentReference.authors.titleAssignments.title.category',
                'parentReference' => fn ($query) => $query->active()->with(['parentReference' => fn ($parent) => $parent->active()]),
                'childReferences' => fn ($query) => $query->active()->with(['parentReference.parentReference', 'childReferences' => fn ($parts) => $parts->active()->with('parentReference.parentReference')]),
            ]);
        });
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
}
