<?php

namespace App\Features\Engagement\EntryPoints\Livewire\Concerns;

use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventSaveCommands;
use App\Features\Events\Api\EventEngagementEligibility;
use App\Models\User;
use App\Shared\Auth\IntendedRedirect;
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

        if (! app(EventEngagementEligibility::class)->isSaveEligible($eventId)) {
            return;
        }

        if (app(EventEngagement::class)->hasSavedEvent((string) $user->getKey(), $eventId)) {
            app(EventSaveCommands::class)->unsave($eventId, (string) $user->getKey());

            return;
        }

        app(EventSaveCommands::class)->save($eventId, (string) $user->getKey());
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

        return app(EventEngagement::class)->savedEventIds((string) $user->getKey());
    }
}
