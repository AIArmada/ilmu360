<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Carbon\CarbonInterface;

/**
 * Upcoming/past event split for public entity pages (venue, series, and any
 * page that renders event cards rather than schedule leaves).
 *
 * The caller owns its Eloquent base query and eager loads; this boundary owns
 * the primary-occurrence time split, totals and ordering.
 */
interface EventPageSchedule
{
    /**
     * @param  object  $baseQuery  Eloquent builder of events.
     * @param  array<int|string, string|array<string, mixed>|\Closure>  $eagerLoads
     * @return array{upcoming: \Illuminate\Database\Eloquent\Collection<int, \App\Features\Events\Models\Event>, past: \Illuminate\Database\Eloquent\Collection<int, \App\Features\Events\Models\Event>, upcoming_total: int, past_total: int}
     */
    public function forQuery(
        object $baseQuery,
        array $eagerLoads,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
    ): array;
}
