<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Delete;

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Api\EventDeletionScope;
use App\Features\Events\Api\EventOccurrenceDeletionPreflight;
use App\Features\Registration\Api\EventRegistrationDeletion;
use App\Models\Event;
use Illuminate\Validation\ValidationException;

/**
 * Must be registered before the vendor occurrence model boots: the vendor
 * deleting event cascades children immediately, before later observers run.
 */
final readonly class GuardEventOccurrenceDeletionAction implements EventOccurrenceDeletionPreflight
{
    public function __construct(
        private EventDeletionScope $deletionScope,
        private EventRegistrationDeletion $registrationDeletion,
    ) {}

    public function handle(EventOccurrence $occurrence): void
    {
        $eventId = (string) $occurrence->event_id;

        if ($this->deletionScope->isDeleting($eventId)) {
            return;
        }

        $event = Event::query()->find($eventId);

        if ($event instanceof Event
            && $event->published_at !== null
            && ! $event->occurrences()->whereKeyNot($occurrence->getKey())->exists()) {
            throw ValidationException::withMessages([
                'occurrences' => __('A published event must have at least one occurrence.'),
            ]);
        }

        $this->registrationDeletion->assertEventRegistrationsCanBeDeleted($eventId);
    }
}
