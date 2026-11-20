<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Event query boundary for public schedule composition and owner listings.
 *
 * The legacy builder method returns an opaque Event query while Discovery
 * composes homepage-only predicates. Newer list reads return scalar rows so
 * engagement, workspace and directory callers never query Event themselves.
 */
interface EventScheduleQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function publicEventQuery(?string $query, array $filters): mixed;

    /**
     * Active events for the supplied ids, in starts_at order.
     *
     * @param  list<string>  $eventIds
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     has_more: bool,
     *     next_page: int|null
     * }
     */
    public function activeEventsForIds(array $eventIds, int $page, int $perPage): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, array{total: int, public: int}>
     */
    public function institutionEventCounts(array $institutionIds): array;

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     last_page: int
     * }
     */
    public function institutionEventsPage(
        string $institutionId,
        string $search,
        string $status,
        string $visibility,
        string $sort,
        int $perPage,
        int $page,
    ): array;

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, array{
     *     events_count: int,
     *     next_event: array{slug: string, title: string, starts_at: string}|null
     * }>
     */
    public function institutionDirectoryFacts(array $institutionIds): array;

    /**
     * Documented transport: a hydrated Event model for framework contracts
     * that cannot be expressed as scalar rows (policies, Livewire page state,
     * eager-loaded relations, media and share subjects). Consumers must not
     * start new business queries from the returned model.
     */
    public function findEvent(string $eventId): ?object;

    /**
     * Documented transport: a hydrated Event model resolved by public slug.
     */
    public function findEventBySlug(string $slug): ?object;

    /**
     * Documented transport: hydrated Event models for schedule leaves, keyed by
     * id and loaded with the supplied relations. Consumers must not issue new
     * business queries from the returned models.
     *
     * @param  list<string>  $eventIds
     * @param  list<string>  $relations
     */
    public function hydrateEventsForIds(array $eventIds, array $relations): object;

    public function eventStatus(string $eventId): ?string;

    public function isPubliclyReachable(string $eventId): bool;

    /**
     * Documented transport: events owned by an organization, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Events\Models\Event>
     */
    public function eventsForOwner(string $ownerType, string $ownerId, int $limit): object;

    /**
     * Documented transport: planner cards for the supplied ids, in starts_at
     * order, with the planner relation set eager loaded.
     *
     * @param  list<string>  $eventIds
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Events\Models\Event>
     */
    public function plannerEventsForIds(array $eventIds, bool $activeOnly): object;

    /**
     * Documented transport: the next recommended planner card, excluding the
     * supplied ids.
     *
     * @param  list<string>  $excludedEventIds
     */
    public function recommendedPlannerEvent(array $excludedEventIds, string $fromUtc): ?object;

    /**
     * Documented transport: events with the admin account relation set.
     *
     * @param  list<string>  $eventIds
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Features\Events\Models\Event>
     */
    public function eventsWithAdminRelations(array $eventIds): object;

    public function eventTable(): string;

    public function keyPersonTable(): string;

    public function seriesTable(): string;

    /**
     * Documented transport: institution dashboard table query with the table
     * relation set and dashboard registration count.
     */
    public function institutionTableQuery(string $institutionId): object;

    /**
     * Documented transport: institution dashboard card query with the card
     * relation set and dashboard registration count.
     */
    public function institutionEventCardsQuery(string $institutionId): object;

    /**
     * Documented transport: correlated institution event count subquery for
     * institution-stat selects. Callers may add read-only predicates.
     */
    public function institutionEventCountSubquery(): object;

    public function institutionRegistrationCount(string $institutionId): int;

    /**
     * Documented transport: a guaranteed-empty event query.
     */
    public function emptyEventsQuery(): object;

    /**
     * Documented transport: person dashboard event query (events the person
     * is involved in, including private/draft workspace rows).
     */
    public function personDashboardQuery(string $personId): object;

    /**
     * Documented transport: public schedule query for a reference family.
     *
     * @param  list<string>  $referenceIds
     * @param  array<string, mixed>  $venueEagerLoads  Caller address joins for venue eager loads.
     */
    public function referenceEventsQuery(array $referenceIds, array $venueEagerLoads): object;

    /**
     * Documented transport: base institution event query.
     */
    public function institutionEventsQuery(string $institutionId): object;

    /**
     * Documented transport: correlated public event count subquery for
     * institution selects.
     */
    public function institutionPublicEventCountSubquery(bool $upcomingOnly = false): object;

    /**
     * Documented transport: institution detail upcoming/past event query with
     * the public detail relation set.
     */
    public function institutionDetailEventsQuery(string $institutionId, bool $past, \DateTimeInterface $now): object;

    /**
     * Documented transport: reference detail upcoming/past event query with
     * the public reference relation set.
     *
     * @param  list<string>  $referenceIds
     */
    public function referenceDetailEventsQuery(array $referenceIds, bool $past, \DateTimeInterface $now): object;

    /**
     * Distinct speakers on the institution's upcoming public events.
     */
    public function institutionSpeakerCount(string $institutionId): int;

    /**
     * Documented transport: public event-count subquery constrained to the
     * reference subtree, for reference directory selects.
     */
    public function publicReferenceEventCountSubquery(): object;
}
