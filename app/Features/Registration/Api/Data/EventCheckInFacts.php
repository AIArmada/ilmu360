<?php

declare(strict_types=1);

namespace App\Features\Registration\Api\Data;

/** Immutable check-in identity snapshot for notification dispatch. */
final readonly class EventCheckInFacts
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $userId,
    ) {}
}
