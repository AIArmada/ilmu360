<?php

namespace App\Actions\Events\Authoring;

use App\Features\Events\Api\EventAuthoring;
use App\Models\Event;
use App\Models\User;
use App\Services\ModerationService;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Interim admin-save adapter.
 *
 * Moderation still lives in the root ModerationService/state machine until the
 * Events lifecycle slice (E3) absorbs it. Everything else delegates to the
 * Events authoring boundary; this class only applies the requested initial
 * status after the feature save commits.
 */
final readonly class SaveAdminEventAction
{
    use AsAction;

    public function __construct(
        private EventAuthoring $authoring,
        private ModerationService $moderationService,
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

        if ($creating) {
            if ($result->requestedStatus === 'pending') {
                $this->moderationService->submitForModeration($event);
            }

            if ($result->requestedStatus === 'approved') {
                $this->moderationService->submitForModeration($event);
                $this->moderationService->approve($event, $actor);
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
