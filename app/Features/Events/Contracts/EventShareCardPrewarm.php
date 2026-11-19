<?php

declare(strict_types=1);

namespace App\Features\Events\Contracts;

/** Root renderer integration used by the Events-owned queue adapter. */
interface EventShareCardPrewarm
{
    public function prewarmEvent(string $eventId): void;
}
