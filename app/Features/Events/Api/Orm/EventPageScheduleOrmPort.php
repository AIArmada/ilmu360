<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Upcoming/past event split for public entity pages (venue, series, and any
 * page that renders event cards rather than schedule leaves).
 *
 * The owner receives scalar venue/series ids and executes the full
 * primary-occurrence time split, totals, ordering and page hydration.
 *
 * @internal Hydrated Event models exist for Livewire page identity and card
 *           relation rendering; query builders remain inside Events.
 *
 * Consumers: Venue and Series public show pages.
 */
interface EventPageScheduleOrmPort
{
    /**
     * @return array{upcoming: EloquentCollection<int, \App\Features\Events\Models\Event>, past: EloquentCollection<int, \App\Features\Events\Models\Event>, upcoming_total: int, past_total: int}
     */
    public function forVenue(
        string $venueId,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array;

    /**
     * @return array{upcoming: EloquentCollection<int, \App\Features\Events\Models\Event>, past: EloquentCollection<int, \App\Features\Events\Models\Event>, upcoming_total: int, past_total: int}
     */
    public function forSeries(
        string $seriesId,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array;
}
