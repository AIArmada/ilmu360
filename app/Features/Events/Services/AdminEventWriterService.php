<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\AdminEventWriter;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventModeration;

final readonly class AdminEventWriterService implements AdminEventWriter
{
    public function __construct(private EventAuthoring $authoring, private EventModeration $moderation) {}

    public function save(array $data, string $actorId, ?string $eventId = null): string
    {
        $creating = $eventId === null;
        $result = $this->authoring->saveAdminEvent($data, $eventId);

        if ($creating) {
            if ($result->requestedStatus === 'pending') {
                $this->moderation->submitForModeration($result->eventId);
            }

            if ($result->requestedStatus === 'approved') {
                $this->moderation->submitForModeration($result->eventId);
                $this->moderation->approve($result->eventId, $actorId);
            }
        }

        return $result->eventId;
    }
}
