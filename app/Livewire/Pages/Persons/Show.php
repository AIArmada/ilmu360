<?php

namespace App\Livewire\Pages\Persons;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventKeyPersonPivot;
use App\Models\Person;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 10;
    }

    /**
     * @return EloquentCollection<int, Event>
     */
    public function getUpcomingEventsProperty(): EloquentCollection
    {
        return $this->personEventQuery()
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at', 'asc')
            ->take($this->upcomingPerPage)
            ->get();
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->personEventQuery()
            ->where('starts_at', '>=', now())
            ->count();
    }

    /**
     * @return EloquentCollection<int, Event>
     */
    public function getPastEventsProperty(): EloquentCollection
    {
        return $this->personEventQuery()
            ->where('starts_at', '<', now())
            ->orderBy('starts_at', 'desc')
            ->take($this->pastPerPage)
            ->get();
    }

    public function getPastTotalProperty(): int
    {
        return $this->personEventQuery()
            ->where('starts_at', '<', now())
            ->count();
    }

    /**
     * @return Collection<int, EventKeyPerson>
     */
    public function getOtherRoleParticipationsProperty(): Collection
    {
        return $this->person->nonSpeakerEventKeyPeople()
            ->whereHas('event', function ($query): void {
                $query->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->whereNotNull('events.published_at');
            })
            // The profile only renders the event title and role here.
            ->with('event')
            ->get()
            ->sortBy(function (EventKeyPerson $keyPerson): int {
                $event = $keyPerson->event;

                return $event instanceof Event && $event->starts_at !== null
                    ? $event->starts_at->timestamp
                    : PHP_INT_MAX;
            })
            ->values();
    }

    public function render(): View
    {
        $this->loadPersonRelations();

        return view('livewire.pages.persons.show');
    }

    private function loadPersonRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->person->loadMissing([
                'media',
                'socialProfiles',
                'addresses',
                'titleAssignments',
            ]);
        });
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
            ->with([
                'institution.addresses',
                'venue.addresses',
                'references',
                'primaryOccurrence',
                'timeExpressions',
            ]);
    }
}
