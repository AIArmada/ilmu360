<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventDeletionScope;
use App\Features\Events\Api\ShareCardWarmScheduler;
use App\Features\Events\Models\Event;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Validation\ValidationException;

final readonly class EventOccurrenceObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private PublicListingsCacheInvalidator $publicListingsCache,
        private EventDeletionScope $eventDeletionScope,
    ) {}

    public function saved(EventOccurrence $occurrence): void
    {
        $this->publicListingsCache->bustMajlisListing();
        $this->syncEventIndex($occurrence);

        app(ShareCardWarmScheduler::class)->prewarmForEvent($occurrence->event_id);
    }

    public function deleting(EventOccurrence $occurrence): void
    {
        if ($this->eventDeletionScope->isDeleting((string) $occurrence->event_id)) {
            return;
        }

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
