<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

/** Authoritative event-going commands, returning transport-neutral scalar state. */
interface EventGoingCommands
{
    /** @return array{status: 'created'|'existing', going_count: int, response_created: bool} */
    public function mark(string $eventId, string $actorId): array;

    /** @return array{deleted: bool, going_count: int} */
    public function remove(string $eventId, string $actorId): array;
}
