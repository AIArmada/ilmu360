<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

final readonly class PublicScheduleRenderedCard
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $title,
        public string $eventStatus,
        public string $entityType,
        public string $html,
    ) {}
}
