<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Cross-feature/public transport entrypoint that queues an Event share-card warm. */
interface ShareCardWarmScheduler
{
    public function prewarmForEvent(mixed $eventId): void;
}
