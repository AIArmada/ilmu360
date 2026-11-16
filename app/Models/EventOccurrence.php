<?php

declare(strict_types=1);

namespace App\Models;

use AIArmada\Events\Models\EventOccurrence as PackageEventOccurrence;
use App\Features\Events\Api\EventDeletionScope;
use App\Features\Registration\Api\EventRegistrationDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Application occurrence used by event relationships.
 *
 * Vendor deletion hooks remove dependent records before an observer may
 * reject the deletion. Preflight the integrity checks first, then wrap the
 * whole vendor cascade and deletion in one transaction.
 */
class EventOccurrence extends PackageEventOccurrence
{
    #[\Override]
    public function getMorphClass(): string
    {
        return (new PackageEventOccurrence)->getMorphClass();
    }

    #[\Override]
    public function delete(): ?bool
    {
        $eventId = (string) $this->event_id;

        if (! app(EventDeletionScope::class)->isDeleting($eventId)) {
            $event = Event::query()->find($eventId);

            if ($event instanceof Event
                && $event->published_at !== null
                && ! $event->occurrences()->whereKeyNot($this->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'occurrences' => __('A published event must have at least one occurrence.'),
                ]);
            }

            app(EventRegistrationDeletion::class)->assertEventRegistrationsCanBeDeleted($eventId);
        }

        return DB::transaction(fn (): ?bool => parent::delete());
    }
}
