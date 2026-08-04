<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Services\PublicScheduleDiscoveryService;
use App\Support\Events\PublicSchedulePolicy;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Session Details')]
class Session extends Component
{
    public Event $event;

    public EventOccurrence $occurrence;

    public EventSession $session;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Event $event, string $occurrenceSlug, string $sessionSlug): void
    {
        if (! $this->isPublicEvent($event)) {
            abort(404);
        }

        $discovery = app(PublicScheduleDiscoveryService::class);
        $occurrence = $discovery->findOccurrence($event, $occurrenceSlug);

        if (! $occurrence instanceof EventOccurrence || ! PublicSchedulePolicy::isPublicOccurrence($occurrence)) {
            abort(404);
        }

        $session = $discovery->findSession($occurrence, $sessionSlug);

        if (! $session instanceof EventSession || ! PublicSchedulePolicy::isMeaningfulSession($session)) {
            abort(404);
        }

        $event->load($discovery->publicRelations());

        /** @var EventOccurrence|null $loadedOccurrence */
        $loadedOccurrence = $event->occurrences->firstWhere('id', $occurrence->getKey());

        if (! $loadedOccurrence instanceof EventOccurrence) {
            abort(404);
        }

        /** @var EventSession|null $loadedSession */
        $loadedSession = $loadedOccurrence->sessions->firstWhere('id', $session->getKey());

        if (! $loadedSession instanceof EventSession) {
            abort(404);
        }

        $this->event = $event;
        $this->occurrence = $loadedOccurrence;
        $this->session = $loadedSession;
    }

    public function render(): View
    {
        return view('livewire.pages.events.session');
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
