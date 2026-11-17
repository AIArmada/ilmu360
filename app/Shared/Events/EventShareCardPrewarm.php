<?php

declare(strict_types=1);

namespace App\Shared\Events;

/** Root renderer integration used by the Events-owned queue adapter. */
interface EventShareCardPrewarm
{
    public function prewarmEvent(string $eventId): void;
}
