<?php

declare(strict_types=1);

namespace App\Features\Engagement\Services;

use App\Features\Engagement\Actions\SaveEventAction;
use App\Features\Engagement\Actions\UnsaveEventAction;
use App\Features\Engagement\Api\EventSaveCommands;
use App\Models\Event;
use App\Models\User;

final readonly class EventSaveCommandsService implements EventSaveCommands
{
    public function __construct(
        private SaveEventAction $save,
        private UnsaveEventAction $unsave,
    ) {}

    public function save(string $eventId, string $actorId): array
    {
        $result = $this->save->handle(
            Event::query()->findOrFail($eventId),
            User::query()->findOrFail($actorId),
            request(),
        );

        return ['bookmark_id' => $result->bookmark !== null ? (string) $result->bookmark->getKey() : null, 'created' => $result->created];
    }

    public function unsave(string $eventId, string $actorId): bool
    {
        return $this->unsave->handle(
            Event::query()->findOrFail($eventId),
            User::query()->findOrFail($actorId),
        )->removed;
    }
}
