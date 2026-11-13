<?php

namespace App\Livewire\Concerns;

use App\Actions\Engagement\SaveEventAction;
use App\Actions\Engagement\UnsaveEventAction;
use App\Models\Event;
use App\Models\User;
use App\Shared\Auth\IntendedRedirect;
use App\Shared\Engagement\EngagementReader;
use Livewire\Attributes\Computed;

trait TogglesEventSave
{
    abstract protected function eventSaveReturnUrl(): string;

    public function toggleSave(string $eventId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(
                IntendedRedirect::loginUrl($this->eventSaveReturnUrl()),
                navigate: true,
            );

            return;
        }

        $event = Event::query()
            ->active()
            ->whereKey($eventId)
            ->first();

        if (! $event instanceof Event || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)) {
            return;
        }

        if (app(EngagementReader::class)->hasSavedEvent((string) $user->getKey(), (string) $event->getKey())) {
            app(UnsaveEventAction::class)->handle($event, $user);

            return;
        }

        app(SaveEventAction::class)->handle($event, $user, request());
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

        return app(EngagementReader::class)->savedEventIds((string) $user->getKey());
    }
}
