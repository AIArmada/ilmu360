<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Carbon\CarbonImmutable;

/** Immutable schedule data needed by Discovery before page models are hydrated. */
final readonly class EventScheduleFact
{
    /**
     * @param  list<EventScheduleTimeFact>  $occurrenceTimeExpressions
     * @param  list<EventScheduleTimeFact>  $eventTimeExpressions
     * @param  list<string>  $sessionIds
     */
    public function __construct(
        public string $eventId,
        public string $eventTitle,
        public string $eventStatus,
        public string $occurrenceId,
        public string $occurrenceTitle,
        public CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public array $occurrenceTimeExpressions,
        public array $eventTimeExpressions,
        public array $sessionIds,
        public bool $isFeatured,
        public ?float $distanceKm,
    ) {}

    public function title(): string
    {
        return trim($this->occurrenceTitle !== '' ? $this->occurrenceTitle : $this->eventTitle);
    }
}
