<?php

namespace App\Actions\Events\Authoring;

use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventModeration;
use App\Models\Event;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Composes feature-owned admin persistence and initial moderation.
 *
 * Filament and admin API entrypoints need the saved Event model for their
 * resource response, while persistence and moderation use the Events APIs.
 */
final readonly class SaveAdminEventAction
{
    use AsAction;

    public function __construct(
        private EventAuthoring $authoring,
        private EventModeration $moderation,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $actor, ?Event $event = null): Event
    {
        $creating = ! $event instanceof Event;
        $result = $this->authoring->saveAdminEvent(
            $data,
            $event instanceof Event ? (string) $event->getKey() : null,
        );

        $event = Event::query()->findOrFail($result->eventId);
        $eventId = (string) $event->getKey();

        if ($creating) {
            if ($result->requestedStatus === 'pending') {
                $this->moderation->submitForModeration($eventId);
            }

            if ($result->requestedStatus === 'approved') {
                $this->moderation->submitForModeration($eventId);
                $this->moderation->approve($eventId, (string) $actor->getKey());
            }
        }

        return $event->fresh([
            'accessPolicy',
            'references',
            'series',
            'classifications',
            'keyPeople',
            'languages',
            'media',
            'institution',
            'venue',
            'primaryLocation.venueSpace',
        ]) ?? $event;
    }
}
