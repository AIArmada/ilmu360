<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Schedule;

use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Actions\UpdateEventOccurrenceAction;
use AIArmada\Events\Models\Event;
use AIArmada\Events\Models\EventOccurrence;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Create or update an occurrence inside a specific event's schedule.
 *
 * The event-scoped lookup is intentional: an occurrence id supplied by the
 * workspace can only resolve through the event being edited.
 */
final readonly class SaveOccurrenceAction
{
    use AsAction;

    public function __construct(
        private CreateEventOccurrenceAction $createOccurrence,
        private UpdateEventOccurrenceAction $updateOccurrence,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Event $event, array $attributes, ?string $occurrenceId = null): EventOccurrence
    {
        if ($occurrenceId === null) {
            return $this->createOccurrence->handle($event, $attributes);
        }

        $occurrence = $event->occurrences()
            ->whereKey($occurrenceId)
            ->firstOrFail();

        $result = $this->updateOccurrence->handle($occurrence, $attributes);

        return $result['occurrence'];
    }
}
