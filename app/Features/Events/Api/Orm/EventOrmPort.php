<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
 * Organizations workspace, Institution dashboard, Person dashboard,
 * References pages, ShareTracking and the Institutions/References lookup
 * queries. Discovery schedule reads use {@see PublicScheduleEventReads}.
 */
interface EventOrmPort extends EventScheduleReads
{
    /**
     * A hydrated Event model for framework contracts (policies, Livewire page
     * state, eager-loaded relations, media and share subjects).
     */
    public function findEvent(string $eventId): ?Event;

    /**
     * Immutable event identity for share URL construction.
     *
     * @return array{id: string, slug: string, title: string}|null
     */
    public function shareIdentityBySlug(string $slug): ?array;

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
     * Counts for a person's event workspace, including private and draft rows.
     *
     * @return array{total: int, upcoming: int, needs_attention: int}
     */
    public function personDashboardEventStats(string $personId): array;

    /**
     * Paginated person workspace rows, filtered and ordered by Events before
     * hydrating the Livewire table models.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    public function personDashboardEventsPage(
        string $personId,
        string $search,
        string $status,
        int $perPage,
        int $page,
    ): LengthAwarePaginator;

    /**
     * Public schedule query for a reference family.
     *
     * @param  list<string>  $referenceIds
     * @param  array<string, mixed>  $venueEagerLoads
     */
    public function referenceEventsQuery(array $referenceIds, array $venueEagerLoads): EventBuilder;
}
