<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Features\Events\Api\Data\EventScheduleCard;

interface EventPageSchedule
{
    /**
     * @return array{upcoming: list<EventScheduleCard>, past: list<EventScheduleCard>, upcoming_total: int, past_total: int}
     */
    public function forVenue(string $venueId, int $upcomingLimit, int $pastLimit): array;

    /**
     * @return array{upcoming: list<EventScheduleCard>, past: list<EventScheduleCard>, upcoming_total: int, past_total: int}
     */
    public function forSeries(string $seriesId, int $upcomingLimit, int $pastLimit): array;
}
