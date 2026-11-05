<?php

namespace App\Livewire\Pages\References;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Events\Models\EventOccurrence;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Data\PublicScheduleLeaf;
use App\Enums\ShareOutcomeType;
use App\Livewire\Concerns\JoinsHierarchyAreaName;
use App\Livewire\Concerns\LoadsEventPageData;
use App\Livewire\Concerns\TogglesEventGoing;
use App\Livewire\Concerns\TogglesEventSave;
use App\Models\Builders\EventBuilder;
use App\Models\Event;
use App\Models\EventReferencePivot;
use App\Models\Reference;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    use LoadsEventPageData;
    use TogglesEventGoing;
    use TogglesEventSave;

    public Reference $reference;

    public int $upcomingPerPage = 6;

    public int $pastPerPage = 6;

    public bool $isFollowing = false;

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
            $follow = Follow::query()
                ->where('follower_type', $user->getMorphClass())
                ->where('follower_id', $user->getKey())
                ->where('followable_type', $this->reference->getMorphClass())
                ->where('followable_id', $this->reference->getKey())
                ->where('status', 'active')
                ->first();

            DB::transaction(function () use ($user, $follow): void {
                $user->unfollow($this->reference);

                if ($follow instanceof Follow) {
                    app(ReverseShareOutcomeAction::class)->handle(
                        ShareOutcomeBusinessKey::follow(ShareOutcomeType::ReferenceFollow, (string) $follow->getKey()),
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
            $created = $user->follow($this->reference);

            // Reactivations reuse the follow row: only rows created by
            // this call freeze a decision, so earlier relationships
            // can never earn later through a new cookie.
            if ($created->wasRecentlyCreated) {
                app(ShareTrackingService::class)->recordOutcome(
                    type: ShareOutcomeType::ReferenceFollow,
                    businessKey: ShareOutcomeBusinessKey::follow(ShareOutcomeType::ReferenceFollow, (string) $created->getKey()),
                    subject: $this->reference,
                    actor: $user,
                    request: request(),
                    metadata: [
                        'reference_id' => $this->reference->getKey(),
                    ],
                    occurredAt: $created->created_at,
                );
            }
        });
        $this->isFollowing = true;
    }

    public function loadMoreUpcoming(): void
    {
        $this->upcomingPerPage += 6;
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 6;
    }

    /**
     * @return Collection<int, PublicScheduleLeaf>
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
     * @return Collection<int, PublicScheduleLeaf>
     */
    public function getPastEventsProperty(): Collection
    {
        return $this->schedulePageData()['past'];
    }

    public function getPastTotalProperty(): int
    {
        return $this->schedulePageData()['past_total'];
    }

    private function eventQuery(): EventBuilder
    {
        /** @var array<string, string|\Closure> $addressRelations */
        $addressRelations = [
            'venue.media',
            'venue.addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
        ];

        $referenceIds = $this->reference->defaultEventReferenceIds();
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
                                ->whereHasMorph('referenceable', [Reference::class], function (Builder $references) use ($referenceIds): void {
                                    Reference::applyPublicVisibility($references)
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
                    Reference::applyPublicVisibility($references->getQuery());
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
     * @param  Collection<int, PublicScheduleLeaf>  $leaves
     * @return Collection<int, PublicScheduleLeaf>
     */
    protected function filterScheduleLeaves(Collection $leaves): Collection
    {
        $referenceIds = $this->reference->defaultEventReferenceIds();

        return $leaves
            ->map(function (PublicScheduleLeaf $leaf) use ($referenceIds): ?PublicScheduleLeaf {
                if ($leaf->event->references->contains(fn (Reference $reference): bool => in_array((string) $reference->getKey(), $referenceIds, true))) {
                    return $leaf;
                }

                $occurrenceMatches = $leaf->occurrence->references->contains(fn ($reference): bool => $reference->visibility === 'public'
                    && $reference->referenceable_type === $this->reference->getMorphClass()
                    && in_array((string) $reference->referenceable_id, $referenceIds, true)
                    && $reference->event_session_id === null);

                if ($occurrenceMatches) {
                    return $leaf;
                }

                $sessions = $leaf->sessions->filter(function ($session) use ($leaf, $referenceIds): bool {
                    return $leaf->occurrence->references->contains(fn ($reference): bool => $reference->visibility === 'public'
                        && $reference->referenceable_type === $this->reference->getMorphClass()
                        && in_array((string) $reference->referenceable_id, $referenceIds, true)
                        && $reference->event_session_id === $session->getKey());
                })->values();

                return $sessions->isEmpty()
                    ? null
                    : new PublicScheduleLeaf($leaf->event, $leaf->occurrence, $sessions);
            })
            ->filter()
            ->values();
    }

    /** @return Builder<Event> */
    protected function eventPageBaseQuery(): Builder
    {
        return $this->eventQuery();
    }

    /** @return array<string|int, string|array<string, mixed>|\Closure> */
    protected function eventPageEagerLoads(): array
    {
        return [
            'venue.media',
            'venue.addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
            'persons.media',
            'persons.titleAssignments.title.category',
            'keyPeople.person',
            'references' => fn ($query) => $query->active(),
            'media',
            'institution.media',
            'classifications.term',
            'primaryOccurrence',
            'displayOccurrence.media',
            'timeExpressions',
            'links',
        ];
    }

    protected function eventPageUpcomingLimit(): int
    {
        return $this->upcomingPerPage;
    }

    protected function eventPagePastLimit(): int
    {
        return $this->pastPerPage;
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
