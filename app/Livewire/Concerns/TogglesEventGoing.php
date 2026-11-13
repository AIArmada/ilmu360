<?php

namespace App\Livewire\Concerns;

use App\Actions\Engagement\MarkEventGoingAction;
use App\Actions\Engagement\RemoveEventGoingAction;
use App\Models\Event;
use App\Models\User;
use App\Shared\Auth\IntendedRedirect;
use App\Shared\Engagement\EngagementReader;
use Livewire\Attributes\Computed;

trait TogglesEventGoing
{
    abstract protected function eventGoingReturnUrl(): string;

    public function toggleGoing(string $eventId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(
                IntendedRedirect::loginUrl($this->eventGoingReturnUrl()),
                navigate: true,
            );

            return;
        }

        $event = Event::query()
            ->active()
            ->whereKey($eventId)
            ->first();

        if (! $event instanceof Event
            || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)
            || ! $this->eventAcceptsGoing($event)) {
            return;
        }

        if (app(EngagementReader::class)->isGoingToEvent(
            (string) $user->getKey(),
            (string) $event->getKey(),
        )) {
            app(RemoveEventGoingAction::class)->handle($event, $user);

            return;
        }

        app(MarkEventGoingAction::class)->handle($event, $user, request());
    }

    protected function eventAcceptsGoing(Event $event): bool
    {
        return $event->goingWindow()->isOpenAt(now());
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function goingEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(EngagementReader::class)->goingEventIds((string) $user->getKey());
    }
}
