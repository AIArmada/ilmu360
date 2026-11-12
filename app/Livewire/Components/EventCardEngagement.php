<?php

namespace App\Livewire\Components;

use AIArmada\Engagement\Models\Bookmark;
use App\Livewire\Concerns\TogglesEventGoing;
use App\Livewire\Concerns\TogglesEventSave;
use App\Models\Event;
use App\Models\User;
use App\Shared\Engagement\EngagementReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EventCardEngagement extends Component
{
    use TogglesEventGoing {
        toggleGoing as protected performToggleGoing;
    }
    use TogglesEventSave {
        toggleSave as protected performToggleSave;
    }

    #[Locked]
    public string $eventId;

    #[Locked]
    public string $returnUrl;

    #[Locked]
    public bool $refreshListing = false;

    public bool $isSaved = false;

    public bool $isGoing = false;

    #[Locked]
    public bool $cardGoingAvailable = false;

    #[Locked]
    public string $signalComponent;

    #[Locked]
    public string $cardSignalEntityType;

    #[Locked]
    public string $cardSignalEntityId;

    public function toggleSave(string $eventId): void
    {
        abort_unless($eventId === $this->eventId, 403);
        $this->performToggleSave($eventId);
        $this->refreshEngagement();
    }

    public function toggleGoing(string $eventId): void
    {
        abort_unless($eventId === $this->eventId, 403);
        $this->performToggleGoing($eventId);
        $this->refreshEngagement();
    }

    #[On('event-card-engagement-updated.{eventId}')]
    public function syncEngagement(string $origin): void
    {
        if ($origin === $this->getId()) {
            $this->skipRender();

            return;
        }

        $this->loadEngagement();
    }

    protected function eventSaveReturnUrl(): string
    {
        return $this->returnUrl;
    }

    protected function eventGoingReturnUrl(): string
    {
        return $this->returnUrl;
    }

    private function refreshEngagement(): void
    {
        if (! auth()->user() instanceof User) {
            return;
        }

        $this->loadEngagement();
        $this->dispatch("event-card-engagement-updated.{$this->eventId}", origin: $this->getId());
        if ($this->refreshListing) {
            $this->dispatch('event-card-list-changed');
        }
    }

    private function loadEngagement(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->isSaved = false;
            $this->isGoing = false;

            return;
        }

        $this->isSaved = Bookmark::forBookmarker($user)
            ->where('bookmarkable_type', (new Event)->getMorphClass())
            ->where('bookmarkable_id', $this->eventId)
            ->active()
            ->exists();
        $this->isGoing = app(EngagementReader::class)->isGoingToEvent(
            (string) $user->getKey(),
            $this->eventId,
        );
    }

    public function render(): View
    {
        return view('livewire.components.event-card-engagement');
    }
}
