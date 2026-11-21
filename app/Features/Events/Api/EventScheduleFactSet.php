<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Immutable event candidate ids and their eligible public schedule leaves. */
final readonly class EventScheduleFactSet
{
    /**
     * @param  list<string>  $eventIds
     * @param  list<EventScheduleFact>  $leaves
     */
    public function __construct(
        public array $eventIds,
        public array $leaves,
    ) {}
}
