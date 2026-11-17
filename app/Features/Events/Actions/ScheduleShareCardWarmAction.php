<?php

declare(strict_types=1);

namespace App\Features\Events\Actions;

use App\Features\Events\Api\ShareCardWarmScheduler;
use App\Features\Events\EntryPoints\Jobs\RenderShareCardJob;

final readonly class ScheduleShareCardWarmAction implements ShareCardWarmScheduler
{
    public function prewarmForEvent(mixed $eventId): void
    {
        RenderShareCardJob::prewarmForEvent($eventId);
    }
}
