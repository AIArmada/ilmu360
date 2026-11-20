<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Http;

use App\Features\Events\Api\EventCategoryPayloads;
use App\Features\Events\EntryPoints\Ui\EventCategoryPresenter;
use App\Features\Events\Models\Event;

/**
 * Boundary adapter over the event category presenter used by payload builders.
 */
final readonly class EventCategoryPayloadPresenter implements EventCategoryPayloads
{
    public function __construct(
        private EventCategoryPresenter $presenter,
    ) {}

    public function forEventPayload(object $event): array
    {
        if (! $event instanceof Event) {
            throw new \InvalidArgumentException(sprintf(
                'EventCategoryPayloadPresenter expects an instance of %s.',
                Event::class,
            ));
        }

        return $this->presenter->forEvent($event);
    }
}
