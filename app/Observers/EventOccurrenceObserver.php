<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class EventOccurrenceObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(EventOccurrence $occurrence): void
    {
        $this->syncEventIndex($occurrence);
    }

    public function deleted(EventOccurrence $occurrence): void
    {
        $this->syncEventIndex($occurrence);
    }

    private function syncEventIndex(EventOccurrence $occurrence): void
    {
        $event = Event::query()->find($occurrence->event_id);

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
