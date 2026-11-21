<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Http;

use App\Features\Events\Api\Orm\EventCategoryPayloads;
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

    public function forEventPayload(Event $event): array
    {
        return $this->presenter->forEvent($event);
    }
}
