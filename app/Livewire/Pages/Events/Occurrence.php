<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Services\PublicScheduleDiscoveryService;
use App\Support\Events\PublicSchedulePolicy;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Occurrence Details')]
class Occurrence extends Component
{
    public Event $event;

    public EventOccurrence $occurrence;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Event $event, string $occurrenceSlug): void
    {
        if (! $this->isPublicEvent($event)) {
            abort(404);
        }

        $discovery = app(PublicScheduleDiscoveryService::class);
        $occurrence = $discovery->findOccurrence($event, $occurrenceSlug);

        if (! $occurrence instanceof EventOccurrence || ! PublicSchedulePolicy::isPublicOccurrence($occurrence)) {
            abort(404);
        }

        $event->load($discovery->publicRelations());

        /** @var EventOccurrence|null $loadedOccurrence */
        $loadedOccurrence = $event->occurrences->firstWhere('id', $occurrence->getKey());

        if (! $loadedOccurrence instanceof EventOccurrence) {
            abort(404);
        }

        $this->event = $event;
        $this->occurrence = $loadedOccurrence;
    }

    public function render(): View
    {
        return view('livewire.pages.events.occurrence');
    }

    private function isPublicEvent(Event $event): bool
    {
        $visibility = $event->visibility instanceof EventVisibility
            ? $event->visibility->value
            : (string) $event->visibility;

        return $event->isPubliclyReachable()
            && in_array($visibility, [
                EventVisibility::Public->value,
                EventVisibility::Unlisted->value,
            ], true);
    }
}
