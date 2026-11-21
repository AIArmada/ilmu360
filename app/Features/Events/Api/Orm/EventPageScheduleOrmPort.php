<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Upcoming/past event split for public entity pages (venue, series, and any
 * page that renders event cards rather than schedule leaves).
 *
 * The owner supplies the already-scoped base query; this port owns the
 * primary-occurrence time split, totals and ordering.
 *
 * @internal Eloquent builder input is limited to an Events-built page query.
 *
 * Consumers: Venue and Series public show pages.
 */
interface EventPageScheduleOrmPort
{
    /**
     * @param  Builder<\App\Features\Events\Models\Event>  $baseQuery
     * @param  array<int|string, string|array<string, mixed>|\Closure>  $eagerLoads
     * @return array{upcoming: \Illuminate\Database\Eloquent\Collection<int, \App\Features\Events\Models\Event>, past: \Illuminate\Database\Eloquent\Collection<int, \App\Features\Events\Models\Event>, upcoming_total: int, past_total: int}
     */
    public function forQuery(
        Builder $baseQuery,
        array $eagerLoads,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array;
}
