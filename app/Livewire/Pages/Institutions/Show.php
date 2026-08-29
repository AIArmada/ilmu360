<?php

namespace App\Livewire\Pages\Institutions;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\DawahShareOutcomeType;
use App\Livewire\Concerns\LoadsEventPageData;
use App\Models\Builders\EventBuilder;
use App\Models\Event;
use App\Models\Institution;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use LoadsEventPageData;

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
            in_array((string) $institution->status, ['verified', 'pending'], true) || $canBypassVisibility,
            404,
        );

        $this->institution = $institution;
        $this->loadInstitutionRelations();

        $this->isFollowing = auth()->user()?->isFollowing($institution) ?? false;
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
            $user->unfollow($this->institution);
            $this->isFollowing = false;

            return;
        }

        $user->follow($this->institution);
        $this->isFollowing = true;

        app(ShareTrackingService::class)->recordOutcome(
            type: DawahShareOutcomeType::InstitutionFollow,
            outcomeKey: 'institution_follow:user:'.$user->id.':institution:'.$this->institution->id,
            subject: $this->institution,
            actor: $user,
            request: request(),
            metadata: [
                'institution_id' => $this->institution->id,
            ],
        );
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
     * @return EloquentCollection<int, Event>
     */
    public function getUpcomingEventsProperty(): EloquentCollection
    {
        return $this->eventPageData()['upcoming'];
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->eventPageData()['upcoming_total'];
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
        return $this->eventPageData()['past_total'];
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
            'venue.addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
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
                'references' => fn ($query) => $query->active(),
                'media',
                'primaryOccurrence',
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
            'venue.addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
            'venue.addresses.city',
            'venue.addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
            'persons.media',
            'persons.titleAssignments.title.category',
            'keyPeople.person',
            'references' => fn ($query) => $query->active(),
            'media',
            'primaryOccurrence',
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
                'addresses' => fn (MorphToMany $relation): MorphToMany => $this->joinAddressStateName($relation),
                'addresses.city',
                'addresses.areaAssignments' => fn (HasMany $relation): HasMany => $this->joinAreaName($relation),
                'contactMethods',
                'publicSocialProfiles',
                'donationChannels.media',
                'persons',
                'persons.media',
                'persons.titleAssignments.title.category',
                'spaces' => fn ($query) => $query->where('status', 'active'),
                'languages',
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
}
