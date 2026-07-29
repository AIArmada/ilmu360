<?php

namespace App\Livewire\Pages\Institutions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\DawahShareOutcomeType;
use App\Livewire\Concerns\LoadsEventPageData;
use App\Models\Builders\EventBuilder;
use App\Models\Event;
use App\Models\Institution;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

        abort_unless($institution->status === 'verified' || $canBypassVisibility, 404);

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

    private function eventQuery(): EventBuilder
    {
        return $this->institution->events()
            ->active()
            ->with([
                'venue.media',
                'venue.addresses.state',
                'venue.addresses.city',
                'venue.addresses.areaAssignments.area',
                'persons.media',
                'persons.titleAssignments.title.category',
                'keyPeople.person',
                'references',
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

    protected function eventPageEagerLoads(): array
    {
        return [
            'venue.media',
            'venue.addresses.state',
            'venue.addresses.city',
            'venue.addresses.areaAssignments.area',
            'persons.media',
            'persons.titleAssignments.title.category',
            'keyPeople.person',
            'references',
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
                'addresses.state',
                'addresses.city',
                'addresses.areaAssignments.area',
                'contactMethods',
                'socialProfiles',
                'donationChannels.media',
                'persons',
                'persons.media',
                'persons.titleAssignments.title.category',
                'spaces' => fn ($query) => $query->where('status', 'active'),
                'languages',
            ]);
        });
    }
}
