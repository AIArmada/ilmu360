<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Validation\ValidationException;

final class EventOccurrenceObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PublicListingsCache $publicListingsCache,
    ) {}

    public function saved(EventOccurrence $occurrence): void
    {
        $this->publicListingsCache->bustMajlisListing();
        $this->syncEventIndex($occurrence);
    }

    public function deleting(EventOccurrence $occurrence): void
    {
        $event = Event::query()->find($occurrence->event_id);

        if (! $event instanceof Event || $event->published_at === null) {
            return;
        }

        if ($event->occurrences()->whereKeyNot($occurrence->getKey())->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'occurrences' => __('A published event must have at least one occurrence.'),
        ]);
    }

    public function deleted(EventOccurrence $occurrence): void
    {
        $this->publicListingsCache->bustMajlisListing();
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
