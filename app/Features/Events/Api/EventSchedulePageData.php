<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Carbon\CarbonInterface;

/**
 * Shared upcoming/past split for event-page schedule sections.
 *
 * Callers own their Livewire state and base query; this boundary owns the
 * leaf load, optional consumer filter, and the public time split.
 */
interface EventSchedulePageData
{
    /**
     * @param  object  $baseQuery  Eloquent builder of events.
     * @param  (callable(\Illuminate\Support\Collection<int, \App\Features\Discovery\Api\PublicScheduleLeaf>): \Illuminate\Support\Collection<int, \App\Features\Discovery\Api\PublicScheduleLeaf>)|null  $filterLeaves
     * @return array{upcoming: \Illuminate\Support\Collection<int, \App\Features\Discovery\Api\PublicScheduleLeaf>, past: \Illuminate\Support\Collection<int, \App\Features\Discovery\Api\PublicScheduleLeaf>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    public function forQuery(
        object $baseQuery,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        ?callable $filterLeaves = null,
    ): array;
}
