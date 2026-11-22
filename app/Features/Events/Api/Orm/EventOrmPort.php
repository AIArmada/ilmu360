<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Explicit Eloquent integration port for event schedule composition.
 *
 * @internal This port exists for framework contracts that cannot be expressed
 *           as scalar rows: Livewire page state, policy subjects, eager-loaded
 *           relation sets, share subjects and owner-registered SQL projections.
 *           Consumers must not start new business queries from returned models
 *           and must not import Event internals directly.
 *
 * Consumers: Registration dashboard/checkout, Identity user dashboard,
 * Organizations workspace, Institution dashboard, References pages,
 * ShareTracking and the Institutions/References lookup
 * queries. Discovery schedule reads use {@see PublicScheduleEventReads}.
 */
interface EventOrmPort
{
    /**
     * A hydrated Event model for framework contracts (policies, Livewire page
     * state, eager-loaded relations, media and share subjects).
     */
    public function findEvent(string $eventId): ?Event;

    /**
     * Institution dashboard table query with the table relation set and
     * dashboard registration count.
     */
    public function institutionTableQuery(string $institutionId): EventBuilder;

    /**
     * Recent institution dashboard cards. Events chooses upcoming cards first,
     * then falls back to the most recent events when no upcoming rows exist.
     *
     * @return EloquentCollection<int, Event>
     */
    public function recentInstitutionEventCards(string $institutionId, int $limit): EloquentCollection;

    /**
     * Pending institution dashboard cards filtered by the current dashboard
     * search, status, and visibility state.
     *
     * @return EloquentCollection<int, Event>
     */
    public function pendingInstitutionEventCards(
        string $institutionId,
        string $search,
        string $status,
        string $visibility,
        int $limit,
    ): EloquentCollection;

    /**
     * A guaranteed-empty event query.
     */
    public function emptyEventsQuery(): EventBuilder;

    /**
     * Public schedule query for a reference family.
     *
     * @param  list<string>  $referenceIds
     * @param  array<string, mixed>  $venueEagerLoads
     */
    public function referenceEventsQuery(array $referenceIds, array $venueEagerLoads): EventBuilder;
}
