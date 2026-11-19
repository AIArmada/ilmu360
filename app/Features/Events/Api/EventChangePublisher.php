<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Features\Events\Api\Enums\EventChangeType;

/** Authorize, mutate, publish, and optionally notify about event changes. */
interface EventChangePublisher
{
    /**
     * @param  array<string, mixed>  $changes
     * @return string Published announcement ID.
     */
    public function publish(
        string $eventId,
        string $actorId,
        EventChangeType|string $type,
        ?string $publicMessage,
        ?string $internalNote = null,
        ?string $replacementEventId = null,
        array $changes = [],
        bool $notify = true,
        ?string $severity = null,
    ): string;
}
