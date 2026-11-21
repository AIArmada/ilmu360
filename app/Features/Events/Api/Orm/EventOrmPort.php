<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Explicit Eloquent integration port for event schedule composition.
 *
 * @internal This port exists for framework contracts that cannot be expressed
 *           as scalar rows: Livewire page state, policy subjects, eager-loaded
 *           relation sets, share subjects and correlated subqueries. Consumers
 *           must not start new business queries from returned models and must
 *           not import Event internals directly.
 *
 * Consumers: Registration dashboard/checkout, Identity user dashboard,
 * Organizations workspace, Institution dashboard, Person dashboard,
 * References pages, ShareTracking and the Institutions/References lookup
 * queries. Discovery schedule reads use {@see PublicScheduleEventReads}.
 */
interface EventOrmPort extends EventScheduleReads
{
    /**
     * Correlated upcoming-occurrence count subquery for the institutions
     * directory listing. The caller applies it with selectSub().
     */
    public function institutionUpcomingCountSubquery(): QueryBuilder;

    /**
     * Correlated next-event id subquery for the institutions directory listing.
     */
    public function institutionNextEventIdSubquery(): EventBuilder;

    /**
     * Card details for the already-selected next event ids, hydrated in one
     * batched query.
     *
     * @param  list<string>  $eventIds
     * @return EloquentCollection<int, Event>
     */
    public function institutionNextEventDetails(array $eventIds): EloquentCollection;

    /**
     * A hydrated Event model for framework contracts (policies, Livewire page
     * state, eager-loaded relations, media and share subjects).
     */
    public function findEvent(string $eventId): ?Event;

    /**
     * A hydrated Event model resolved by public slug.
     */
    public function findEventBySlug(string $slug): ?Event;

    /**
     * Hydrated Event models for schedule leaves, keyed by id and loaded with
     * the supplied relations.
     *
     * @param  list<string>  $eventIds
     * @param  list<string>  $relations
     * @return EloquentCollection<string, Event>
     */
    public function hydrateEventsForIds(array $eventIds, array $relations): EloquentCollection;

    /**
     * Events owned by an organization, newest first.
     *
     * @return EloquentCollection<int, Event>
     */
    public function eventsForOwner(string $ownerType, string $ownerId, int $limit): EloquentCollection;

    /**
     * Planner cards for the supplied ids, in starts_at order, with the planner
     * relation set eager loaded.
     *
     * @param  list<string>  $eventIds
     * @return EloquentCollection<int, Event>
     */
    public function plannerEventsForIds(array $eventIds, bool $activeOnly): EloquentCollection;

    /**
     * The next recommended planner card, excluding the supplied ids.
     *
     * @param  list<string>  $excludedEventIds
     */
    public function recommendedPlannerEvent(array $excludedEventIds, string $fromUtc): ?Event;

    /**
     * Events with the admin account relation set.
     *
     * @param  list<string>  $eventIds
     * @return EloquentCollection<int, Event>
     */
    public function eventsWithAdminRelations(array $eventIds): EloquentCollection;

    /**
     * Institution dashboard table query with the table relation set and
     * dashboard registration count.
     */
    public function institutionTableQuery(string $institutionId): EventBuilder;

    /**
     * Institution dashboard card query with the card relation set and
     * dashboard registration count.
     */
    public function institutionEventCardsQuery(string $institutionId): EventBuilder;

    /**
     * A guaranteed-empty event query.
     */
    public function emptyEventsQuery(): EventBuilder;

    /**
     * Person dashboard event query (events the person is involved in,
     * including private/draft workspace rows).
     */
    public function personDashboardQuery(string $personId): EventBuilder;

    /**
     * Public schedule query for a reference family.
     *
     * @param  list<string>  $referenceIds
     * @param  array<string, mixed>  $venueEagerLoads
     */
    public function referenceEventsQuery(array $referenceIds, array $venueEagerLoads): EventBuilder;
}
