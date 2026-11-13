<?php

namespace App\Livewire\Pages\Institutions;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Engagement\FollowSubjectAction;
use App\Actions\Engagement\UnfollowSubjectAction;
use App\Data\PublicScheduleLeaf;
use App\Features\Directory\Api\ReferenceQueries;
use App\Livewire\Concerns\JoinsHierarchyAreaName;
use App\Livewire\Concerns\LoadsEventPageData;
use App\Livewire\Concerns\TogglesEventGoing;
use App\Livewire\Concerns\TogglesEventSave;
use App\Models\Builders\EventBuilder;
use App\Models\Event;
use App\Models\EventReferencePivot;
use App\Models\Institution;
use App\Models\Reference;
use App\Shared\Auth\IntendedRedirect;
use App\Shared\Directory\Enums\InstitutionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
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

    public Institution $institution;

    public int $upcomingPerPage = 6;

    public int $pastPerPage = 6;

    public bool $isFollowing = false;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Institution $institution): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless(
            in_array($institution->status, InstitutionStatus::publiclyVisible(), true) || $canBypassVisibility,
            404,
        );

        $this->institution = $institution;
        $this->loadInstitutionRelations();

        $this->isFollowing = auth()->user()?->isFollowing($institution) ?? false;
    }

    protected function eventSaveReturnUrl(): string
    {
        return route('institutions.show', $this->institution, absolute: false);
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
                IntendedRedirect::loginUrl(route('institutions.show', $this->institution, absolute: false)),
                navigate: true,
            );

            return;
        }

        if ($this->isFollowing) {
            app(UnfollowSubjectAction::class)->handle($this->institution, $user);
            $this->isFollowing = false;

            return;
        }

        app(FollowSubjectAction::class)->handle(
            subject: $this->institution,
            user: $user,
            metadata: ['institution_id' => $this->institution->getKey()],
            request: request(),
        );
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

    public function getHasAdminOrOwnerMemberProperty(): bool
    {
        return OwnerContext::withOwner(null, fn (): bool => $this->institution->members()
            ->wherePivotIn('role', [
                MemberRole::Admin->value,
                MemberRole::Owner->value,
            ])
            ->exists());
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

        /** @var EventBuilder $query */
        $query = $this->institution->events()->getQuery();

        return $query
            ->active()
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
            ]);
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
            'venue.addresses' => $this->joinAddressStateName(...),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => $this->joinAreaName(...),
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
        $this->loadInstitutionRelations();

        return view('livewire.pages.institutions.show');
    }

    private function loadInstitutionRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->institution->loadMissing([
                'media',
                'addresses' => $this->joinAddressStateName(...),
                'addresses.city',
                'addresses.areaAssignments' => $this->joinAreaName(...),
                'contactMethods',
                'publicSocialProfiles',
                'donationChannels.media',
                'persons',
                'persons.media',
                'persons.titleAssignments.title.category',
                'languages',
            ]);
        });
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
}
