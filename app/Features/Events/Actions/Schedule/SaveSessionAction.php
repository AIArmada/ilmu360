<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Schedule;

use AIArmada\Events\Actions\CreateEventSessionAction;
use AIArmada\Events\Actions\UpdateEventSessionAction;
use AIArmada\Events\Models\Event;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Create or update a session inside a specific event's schedule.
 *
 * Creation resolves the occurrence through the edited event; updates resolve
 * the session through the event id, preserving the workspace's existing
 * event-scoped behaviour.
 */
final readonly class SaveSessionAction
{
    use AsAction;

    public function __construct(
        private CreateEventSessionAction $createSession,
        private UpdateEventSessionAction $updateSession,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        Event $event,
        string $occurrenceId,
        array $attributes,
        ?string $sessionId = null,
    ): EventSession {
        if ($sessionId !== null) {
            $session = EventSession::query()
                ->whereKey($sessionId)
                ->where('event_id', $event->getKey())
                ->firstOrFail();

            $result = $this->updateSession->handle($session, $attributes);

            return $result['session'];
        }

        /** @var EventOccurrence $occurrence */
        $occurrence = $event->occurrences()
            ->whereKey($occurrenceId)
            ->firstOrFail();

        return $this->createSession->handle($occurrence, $attributes);
    }
}
