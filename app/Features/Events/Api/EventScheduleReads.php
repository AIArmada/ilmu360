<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Features\Events\Api\Data\OrganizationWorkspaceEvent;
use App\Features\Events\Api\Data\PersonDashboardEventPage;

/**
 * Scalar event-schedule reads owned by the Events feature.
 *
 * Ordinary cross-owner reads return immutable rows, counts and page metadata.
 * All inputs and outputs at this boundary are scalar or immutable values.
 */
interface EventScheduleReads
{
    /**
     * Public event candidates matching the owner-applied parent filters, with
     * immutable eligible schedule facts for Discovery's leaf-level filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function publicScheduleFactSet(?string $query, array $filters): EventScheduleFactSet;

    /**
     * Homepage candidate events: featured occurrences plus events followed by
     * the supplied person, institution or reference ids.
     *
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     */
    public function homepageScheduleFacts(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
    ): EventScheduleFactSet;

    /**
     * Schedule facts for page-owned event ids. Discovery uses only ids from
     * its input models; Events owns the query and eligibility policy.
     *
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     */
    public function scheduleFactsForEventIds(array $eventIds, array $distanceByEventId = []): EventScheduleFactSet;

    /**
     * Event ids matched by the homepage follow predicates, used to order the
     * non-featured homepage selection.
     *
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public function homepageFollowedEventIds(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
    ): array;

    /**
     * Immutable event identity for share URL construction.
     *
     * @return array{id: string, slug: string, title: string}|null
     */
    public function shareIdentityBySlug(string $slug): ?array;

    /** @return array{id: string, title: string}|null */
    public function shareIdentityById(string $eventId): ?array;

    /**
     * Counts for a person's event workspace, including private and draft rows.
     *
     * @return array{total: int, upcoming: int, needs_attention: int}
     */
    /** @return list<OrganizationWorkspaceEvent> */
    public function organizationWorkspaceEvents(string $ownerType, string $ownerId, int $limit): array;

    public function personDashboardEventsPage(
        string $personId,
        string $search,
        string $status,
        int $perPage,
        int $page,
    ): PersonDashboardEventPage;

    public function personDashboardEventStats(string $personId): array;

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

    public function eventStatus(string $eventId): ?string;

    public function isPubliclyReachable(string $eventId): bool;

    public function eventTable(): string;

    public function keyPersonTable(): string;

    public function seriesTable(): string;

    public function institutionRegistrationCount(string $institutionId): int;

    /**
     * Distinct speakers on the institution's upcoming public events.
     */
    public function institutionSpeakerCount(string $institutionId): int;

    /**
     * Institution event counts by category, keyed by institution id.
     *
     * @param  list<string>  $institutionIds
     * @return array<string, array{total: int, public: int, upcoming: int, draft: int, pending: int}>
     */
    public function institutionEventStats(array $institutionIds): array;

    /**
     * Public event counts for reference directory families.
     *
     * @param  array<string, list<string>>  $referenceFamilies  Family reference ids keyed by directory root id.
     * @return array<string, int>
     */
    public function publicEventCountsForReferenceFamilies(array $referenceFamilies): array;

    /**
     * Next upcoming event and upcoming-occurrence count per reference family.
     *
     * @param  array<string, list<string>>  $referenceFamilies  Family reference ids keyed by directory root id.
     * @return array<string, array{next_event_id: ?string, upcoming_events_count: int}>
     */
    public function referenceDirectoryFacts(array $referenceFamilies): array;
}
