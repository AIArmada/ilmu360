<?php

namespace App\Livewire\Pages\Speakers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\EventKeyPersonPivot;
use App\Models\Speaker;
use App\Services\ShareTrackingService;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Speaker $speaker;

    public int $upcomingPerPage = 10;

    public int $pastPerPage = 10;

    public bool $isFollowing = false;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Speaker $speaker): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless($speaker->is_active, 404);
        abort_unless($speaker->status === 'verified' || $canBypassVisibility, 404);

        $this->speaker = $speaker;
        $this->loadSpeakerRelations();
        $this->isFollowing = auth()->user()?->isFollowing($speaker) ?? false;
    }

    public function toggleFollow(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->redirect(
                IntendedRedirect::loginUrl(route('speakers.show', $this->speaker, absolute: false)),
                navigate: true,
            );

            return;
        }

        if ($this->isFollowing) {
            $user->unfollow($this->speaker);
            $this->isFollowing = false;

            return;
        }

        $user->follow($this->speaker);
        $this->isFollowing = true;

        app(ShareTrackingService::class)->recordOutcome(
            type: DawahShareOutcomeType::SpeakerFollow,
            outcomeKey: 'speaker_follow:user:'.$user->id.':speaker:'.$this->speaker->id,
            subject: $this->speaker,
            actor: $user,
            request: request(),
            metadata: [
                'speaker_id' => $this->speaker->id,
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
        return $this->speakerEventQuery()
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at', 'asc')
            ->take($this->upcomingPerPage)
            ->get();
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->speakerEventQuery()
            ->where('starts_at', '>=', now())
            ->count();
    }

    /**
     * @return EloquentCollection<int, Event>
     */
    public function getPastEventsProperty(): EloquentCollection
    {
        return $this->speakerEventQuery()
            ->where('starts_at', '<', now())
            ->orderBy('starts_at', 'desc')
            ->take($this->pastPerPage)
            ->get();
    }

    public function getPastTotalProperty(): int
    {
        return $this->speakerEventQuery()
            ->where('starts_at', '<', now())
            ->count();
    }

    /**
     * @return Collection<int, EventKeyPerson>
     */
    public function getOtherRoleParticipationsProperty(): Collection
    {
        return $this->speaker->nonSpeakerEventKeyPeople()
            // @phpstan-ignore-next-line — active() is a scope on Event, but $query is inferred as Builder<Model>
            ->whereHas('event', fn ($query) => $query->active())
            ->with([
                'event.institution.address',
                'event.venue.address',
                'event.references',
                'event.media',
            ])
            ->get()
            ->sortBy(fn (EventKeyPerson $keyPerson): int => $keyPerson->event?->starts_at->timestamp ?? PHP_INT_MAX)
            ->values();
    }

    public function render(): View
    {
        $this->loadSpeakerRelations();

        return view('livewire.pages.speakers.show');
    }

    private function loadSpeakerRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->speaker->load([
                'media',
                'contacts',
                'socialMedia',
                'address',
                'institutions' => fn ($query) => $query->orderByPivot('is_primary', 'desc')->limit(3),
                'institutions.media',
            ]);
        });
    }

    /**
     * @return BelongsToMany<Event, Speaker, EventKeyPersonPivot, 'pivot'>
     */
    private function speakerEventQuery(): BelongsToMany
    {
        return $this->speaker->speakerEvents()
            ->active()
            ->with([
                'institution.address',
                'venue.address',
                'references',
                'media',
            ]);
    }
}
