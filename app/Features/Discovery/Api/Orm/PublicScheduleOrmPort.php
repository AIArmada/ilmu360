<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api\Orm;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Models\User;

/**
 * Explicit Eloquent integration port for the public schedule pages.
 *
 * @internal The public pages need hydrated event/occurrence/session models for
 *           Livewire state, relation rendering and page-scoped eager loads.
 *           This port owns that model seam; ordinary cross-owner reads belong
 *           in owner scalar/card APIs.
 *
 * Consumers: Events Index/Show/Occurrence/Session pages, Institution, Person
 * and Reference Show pages, and EventSchedulePageData.
 */
interface PublicScheduleOrmPort
{
    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, PublicScheduleModelLeaf>
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 12,
        string $sort = 'time',
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $radiusKm = null,
    );

    /**
     * @return \Illuminate\Support\Collection<int, PublicScheduleModelLeaf>
     */
    public function homepageSelection(?User $user);

    /**
     * @param  iterable<int, \App\Features\Events\Models\Event>  $events
     * @return \Illuminate\Support\Collection<int, PublicScheduleModelLeaf>
     */
    public function leavesForEvents(iterable $events);

    /**
     * Build one leaf from already-loaded models, so pages that own the
     * occurrence/session load and visibility filter keep their query budget.
     *
     * @param  iterable<int, object>  $sessions
     */
    public function leafFor(object $event, object $occurrence, iterable $sessions = []): PublicScheduleModelLeaf;

    public function findOccurrence(object $event, string $slug): ?EventOccurrence;

    public function findSession(EventOccurrence $occurrence, string $slug): ?EventSession;

    public function hydrateOccurrencePageVenues(object $event): void;
}
