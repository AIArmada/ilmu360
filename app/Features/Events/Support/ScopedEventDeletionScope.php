<?php

declare(strict_types=1);

namespace App\Features\Events\Support;

use App\Features\Events\Api\EventDeletionScope;

final class ScopedEventDeletionScope implements EventDeletionScope
{
    /** @var list<string> */
    private array $deletingEventIds = [];

    public function whileDeleting(string $eventId, callable $callback): mixed
    {
        $this->deletingEventIds[] = $eventId;

        try {
            return $callback();
        } finally {
            array_pop($this->deletingEventIds);
        }
    }

    public function isDeleting(string $eventId): bool
    {
        return in_array($eventId, $this->deletingEventIds, true);
    }
}
