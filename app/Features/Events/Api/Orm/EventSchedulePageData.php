<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Features\Events\Models\Builders\EventBuilder;
use Carbon\CarbonInterface;

/**
 * Shared upcoming/past split for event-page schedule sections.
 *
 * Callers own their Livewire state and base query; this boundary owns the
 * leaf load, optional consumer filter, and the public time split.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, References/EntryPoints, Events/Services, Institutions/EntryPoints
 */
interface EventSchedulePageData
{
    /**
     * @param  EventBuilder  $baseQuery  Events-owned base query.
     * @param  (callable(\Illuminate\Support\Collection<int, \App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf>): \Illuminate\Support\Collection<int, \App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf>)|null  $filterLeaves
     * @return array{upcoming: \Illuminate\Support\Collection<int, \App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf>, past: \Illuminate\Support\Collection<int, \App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    public function forQuery(
        EventBuilder $baseQuery,
        int $upcomingLimit,
        int $pastLimit,
        ?CarbonInterface $rangeFrom = null,
        ?CarbonInterface $rangeTo = null,
        ?callable $filterLeaves = null,
    ): array;
}
