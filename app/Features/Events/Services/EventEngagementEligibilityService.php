<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\EventEngagementEligibility;
use App\Features\Events\Models\Event;

/**
 * Single owner of the active/engageable/window rules used by engagement.
 */
final readonly class EventEngagementEligibilityService implements EventEngagementEligibility
{
    public function isGoingEligible(string $eventId): bool
    {
        $event = $this->engageableEvent($eventId);

        return $event instanceof Event && $event->goingWindow()->isOpenAt(now());
    }

    public function isSaveEligible(string $eventId): bool
    {
        return $this->engageableEvent($eventId) instanceof Event;
    }

    private function engageableEvent(string $eventId): ?Event
    {
        $event = Event::query()
            ->active()
            ->whereKey($eventId)
            ->first();

        if (! $event instanceof Event || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)) {
            return null;
        }

        return $event;
    }
}
