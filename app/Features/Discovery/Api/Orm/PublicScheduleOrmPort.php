<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api\Orm;

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
     * @return LengthAwarePaginator<int, PublicScheduleModelLeaf>
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 12,
        string $sort = 'time',
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $radiusKm = null,
    ): LengthAwarePaginator;

    /**
     * @return Collection<int, PublicScheduleModelLeaf>
     */
    public function homepageSelection(?User $user): Collection;

    /**
     * @param  iterable<int, Event>  $events
     * @return Collection<int, PublicScheduleModelLeaf>
     */
    public function leavesForEvents(iterable $events): Collection;

    /**
     * Build one leaf from already-loaded models, so pages that own the
     * occurrence/session load and visibility filter keep their query budget.
     *
     * @param  iterable<int, object>  $sessions
     */
    public function leafFor(Event $event, EventOccurrence $occurrence, iterable $sessions = []): PublicScheduleModelLeaf;

    public function hydrateOccurrencePageVenues(Event $event): void;
}
