<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Api\EventProjections;
use App\Features\Events\Models\Event;

final readonly class SyncPendingEventProjectionsAction
{
    public function __construct(private EventProjections $projections) {}

    public function handle(Event $event): void
    {
        $pending = $event->pendingProjectionWrites();
        $eventId = (string) $event->getKey();

        if ($pending['links'] !== []) {
            $this->projections->syncLinks($eventId, $pending['links']);
        }

        if ($pending['audiences'] !== [] || $pending['profiles'] !== []) {
            $this->projections->syncAudiences($eventId, $pending['audiences'], $pending['profiles']);
        }

        $event->clearPendingProjectionWrites();
    }
}
