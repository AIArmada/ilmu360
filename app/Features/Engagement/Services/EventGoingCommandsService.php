<?php

declare(strict_types=1);

namespace App\Features\Engagement\Services;

use App\Features\Engagement\Actions\MarkEventGoingAction;
use App\Features\Engagement\Actions\RemoveEventGoingAction;
use App\Features\Engagement\Api\EventGoingCommands;
use App\Models\Event;
use App\Models\User;

final readonly class EventGoingCommandsService implements EventGoingCommands
{
    public function __construct(
        private MarkEventGoingAction $markAction,
        private RemoveEventGoingAction $removeAction,
    ) {}

    public function mark(string $eventId, string $actorId): array
    {
        return $this->markAction->handle(
            Event::query()->findOrFail($eventId),
            User::query()->findOrFail($actorId),
            request(),
        );
    }

    public function remove(string $eventId, string $actorId): array
    {
        return $this->removeAction->handle(
            Event::query()->findOrFail($eventId),
            User::query()->findOrFail($actorId),
        );
    }
}
