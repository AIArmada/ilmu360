<?php

namespace App\Features\Engagement\EntryPoints\Livewire\Concerns;

use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventGoingCommands;
use App\Features\Events\Api\EventEngagementEligibility;
use App\Models\User;
use App\Shared\Auth\IntendedRedirect;
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

        if (! app(EventEngagementEligibility::class)->isGoingEligible($eventId)) {
            return;
        }

        if (app(EventEngagement::class)->isGoingToEvent(
            (string) $user->getKey(),
            $eventId,
        )) {
            app(EventGoingCommands::class)->remove($eventId, (string) $user->getKey());

            return;
        }

        app(EventGoingCommands::class)->mark($eventId, (string) $user->getKey());
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

        return app(EventEngagement::class)->goingEventIds((string) $user->getKey());
    }
}
