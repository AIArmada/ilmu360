<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Discovery;

use App\Features\Events\Models\Event;

final readonly class SyncEventSearchIndexAction
{
    public function handle(string $eventId): void
    {
        $event = Event::query()->find($eventId);

        if (! $event instanceof Event) {
            return;
        }

        if ($event->shouldBeSearchable()) {
            $event->searchable();
        } elseif ($event->wasSearchableBeforeUpdate()) {
            $event->unsearchable();
        }
    }
}
