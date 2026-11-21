<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Actions\Discovery\RefreshOccurrenceDiscoveryAction;
use App\Features\Events\Api\EventDeletionScope;
use App\Features\Events\Models\Event;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Validation\ValidationException;

final readonly class EventOccurrenceObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RefreshOccurrenceDiscoveryAction $discovery,
        private EventDeletionScope $eventDeletionScope,
    ) {}

    public function saved(EventOccurrence $occurrence): void
    {
        $this->discovery->afterSaved((string) $occurrence->event_id);
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
        $this->discovery->afterDeleted((string) $occurrence->event_id);
    }

}
