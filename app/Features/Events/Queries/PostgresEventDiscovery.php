<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Discovery\Api\Data\EventSearchHitIds;
use App\Features\Events\Api\Data\AdminEventCard;
use App\Features\Events\Api\Data\EventDiscoveryPage;
use App\Features\Events\Api\Data\InstitutionDashboardEventCard;
use App\Features\Events\Api\Data\PersonDashboardEventPage;
use App\Features\Events\Api\Data\PlannerEventCard;
use App\Features\Events\Api\EventCollections;
use App\Features\Events\Api\EventDirectoryProjectionProvider;
use App\Features\Events\Api\EventDirectorySqlProjection;
use App\Features\Events\Api\EventDiscoveryReader;
use App\Features\Events\Api\EventScheduleFactSet;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\Postgres\EventFilterQueryBuilder;
use App\Features\Events\Queries\Postgres\EventHomepageAndCollectionQueries;
use App\Features\Events\Queries\Postgres\EventScheduleHydrator;
use App\Features\Events\Queries\Postgres\EventScheduleOccurrencePaginator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final readonly class PostgresEventDiscovery implements EventCollections, EventDirectoryProjectionProvider, EventDiscoveryReader, EventScheduleReads
{
    public function __construct(
        private EventFilterQueryBuilder $filterQueryBuilder,
        private EventScheduleOccurrencePaginator $schedulePaginator,
        private EventHomepageAndCollectionQueries $homepageAndCollectionQueries,
        private EventScheduleHydrator $scheduleHydrator,
    ) {}

    public function search(EventDiscoveryCriteria $criteria): EventDiscoveryPage
    {
        return $this->filterQueryBuilder->search($criteria);
    }

    public function searchIds(EventDiscoveryCriteria $criteria): EventSearchHitIds
    {
        return $this->filterQueryBuilder->searchIds($criteria);
    }

    public function nearby(EventDiscoveryCriteria $criteria): EventDiscoveryPage
    {
        return $this->filterQueryBuilder->nearby($criteria);
    }

    public function nearbyIds(EventDiscoveryCriteria $criteria): EventSearchHitIds
    {
        return $this->filterQueryBuilder->nearbyIds($criteria);
    }

    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): EventDiscoveryPage
    {
        return $this->filterQueryBuilder->nearbyWithQuery($criteria);
    }

    public function nearbyWithQueryIds(EventDiscoveryCriteria $criteria): EventSearchHitIds
    {
        return $this->filterQueryBuilder->nearbyWithQueryIds($criteria);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function publicScheduleFactSet(?string $query, array $filters): EventScheduleFactSet
    {
        return $this->schedulePaginator->publicScheduleFactSet($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $parentFilters
     * @param  array<string, mixed>  $timeFilters
     */
    public function publicScheduleFactSetForWindow(?string $query, array $parentFilters, array $timeFilters): EventScheduleFactSet
    {
        return $this->schedulePaginator->publicScheduleFactSetForWindow($query, $parentFilters, $timeFilters);
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     */
    public function homepageScheduleFacts(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
    ): EventScheduleFactSet {
        return $this->homepageAndCollectionQueries->homepageScheduleFacts($personIds, $institutionIds, $referenceIds);
    }

    /**
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     */
    public function scheduleFactsForEventIds(array $eventIds, array $distanceByEventId = []): EventScheduleFactSet
    {
        return $this->schedulePaginator->scheduleFactsForEventIds($eventIds, $distanceByEventId);
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     * @return list<string>
     */
    public function homepageFollowedEventIds(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
    ): array {
        return $this->homepageAndCollectionQueries->homepageFollowedEventIds($personIds, $institutionIds, $referenceIds);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array{event_id: string, occurrence_id: string}>, total: int, has_matching_events: bool}
     */
    public function paginatePublicScheduleOccurrences(
        ?string $query,
        array $filters,
        int $page,
        int $perPage,
        string $sort = 'time',
    ): array {
        return $this->schedulePaginator->paginatePublicScheduleOccurrences($query, $filters, $page, $perPage);
    }

    /**
     * @return list<array{event_id: string, occurrence_id: string}>
     */
    public function homepageFeaturedOccurrences(int $limit = 9): array
    {
        return $this->homepageAndCollectionQueries->homepageFeaturedOccurrences($limit);
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     * @param  list<string>  $excludeOccurrenceIds
     * @return list<array{event_id: string, occurrence_id: string}>
     */
    public function homepageFollowedOccurrences(
        array $personIds,
        array $institutionIds,
        array $referenceIds,
        CarbonInterface $startsBeforeUtc,
        array $excludeOccurrenceIds = [],
        int $limit = 50,
    ): array {
        return $this->homepageAndCollectionQueries->homepageFollowedOccurrences(
            $personIds,
            $institutionIds,
            $referenceIds,
            $startsBeforeUtc,
            $excludeOccurrenceIds,
            $limit,
        );
    }

    /**
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     * @param  list<string>|null  $occurrenceIds
     * @return EloquentCollection<int, Event>
     */
    public function hydrateScheduleEvents(
        array $eventIds,
        bool $withOccurrenceReferences = false,
        array $distanceByEventId = [],
        ?array $occurrenceIds = null,
    ): EloquentCollection {
        return $this->scheduleHydrator->hydrateScheduleEvents(
            $eventIds,
            $withOccurrenceReferences,
            $distanceByEventId,
            $occurrenceIds,
        );
    }

    /**
     * @param  array<int|string, mixed>  $relations
     */
    public function institutionScheduleQuery(string $institutionId, array $relations): EventBuilder
    {
        return $this->homepageAndCollectionQueries->institutionScheduleQuery($institutionId, $relations);
    }

    public function activeEventsForIds(array $eventIds, int $page, int $perPage): array
    {
        return $this->homepageAndCollectionQueries->activeEventsForIds($eventIds, $page, $perPage);
    }

    public function institutionEventCounts(array $institutionIds): array
    {
        return $this->homepageAndCollectionQueries->institutionEventCounts($institutionIds);
    }

    public function institutionEventsPage(
        string $institutionId,
        string $search,
        string $status,
        string $visibility,
        string $sort,
        int $perPage,
        int $page,
    ): array {
        return $this->homepageAndCollectionQueries->institutionEventsPage(
            $institutionId,
            $search,
            $status,
            $visibility,
            $sort,
            $perPage,
            $page,
        );
    }

    public function institutionDirectoryEventColumns(): EventDirectorySqlProjection
    {
        return $this->homepageAndCollectionQueries->institutionDirectoryEventColumns();
    }

    /**
     * @param  list<string>  $eventIds
     * @return array<string, array{slug: string, title: string, next_event_starts_at: string}>
     */
    public function institutionNextEventDetails(array $eventIds): array
    {
        return $this->homepageAndCollectionQueries->institutionNextEventDetails($eventIds);
    }

    public function institutionDirectoryFacts(array $institutionIds): array
    {
        return $this->homepageAndCollectionQueries->institutionDirectoryFacts($institutionIds);
    }

    public function shareIdentityById(string $eventId): ?array
    {
        return $this->homepageAndCollectionQueries->shareIdentityById($eventId);
    }

    public function shareIdentityBySlug(string $slug): ?array
    {
        return $this->homepageAndCollectionQueries->shareIdentityBySlug($slug);
    }

    public function eventStatus(string $eventId): ?string
    {
        return $this->homepageAndCollectionQueries->eventStatus($eventId);
    }

    public function isPubliclyReachable(string $eventId): bool
    {
        return $this->homepageAndCollectionQueries->isPubliclyReachable($eventId);
    }

    public function organizationWorkspaceEvents(string $ownerType, string $ownerId, int $limit): array
    {
        return $this->homepageAndCollectionQueries->organizationWorkspaceEvents($ownerType, $ownerId, $limit);
    }

    /** @return list<PlannerEventCard> */
    public function plannerEventCardsForIds(array $eventIds, bool $activeOnly): array
    {
        return $this->homepageAndCollectionQueries->plannerEventCardsForIds($eventIds, $activeOnly);
    }

    /** @param list<string> $excludedEventIds */
    public function recommendedPlannerEventCard(array $excludedEventIds, string $fromUtc): ?PlannerEventCard
    {
        return $this->homepageAndCollectionQueries->recommendedPlannerEventCard($excludedEventIds, $fromUtc);
    }

    /** @return list<AdminEventCard> */
    public function adminEventCardsForIds(array $eventIds): array
    {
        return $this->homepageAndCollectionQueries->adminEventCardsForIds($eventIds);
    }

    public function eventTable(): string
    {
        return $this->homepageAndCollectionQueries->eventTable();
    }

    public function keyPersonTable(): string
    {
        return $this->homepageAndCollectionQueries->keyPersonTable();
    }

    public function seriesTable(): string
    {
        return $this->homepageAndCollectionQueries->seriesTable();
    }

    /** @return list<InstitutionDashboardEventCard> */
    public function recentInstitutionEventCards(string $institutionId, int $limit): array
    {
        return $this->homepageAndCollectionQueries->recentInstitutionEventCards($institutionId, $limit);
    }

    /** @return list<InstitutionDashboardEventCard> */
    public function pendingInstitutionEventCards(
        string $institutionId,
        string $search,
        string $status,
        string $visibility,
        int $limit,
    ): array {
        return $this->homepageAndCollectionQueries->pendingInstitutionEventCards(
            $institutionId,
            $search,
            $status,
            $visibility,
            $limit,
        );
    }

    public function institutionRegistrationCount(string $institutionId): int
    {
        return $this->homepageAndCollectionQueries->institutionRegistrationCount($institutionId);
    }

    /** @return array{total: int, upcoming: int, needs_attention: int} */
    public function personDashboardEventStats(string $personId): array
    {
        return $this->homepageAndCollectionQueries->personDashboardEventStats($personId);
    }

    public function personDashboardEventsPage(
        string $personId,
        string $search,
        string $status,
        int $perPage,
        int $page,
    ): PersonDashboardEventPage {
        return $this->homepageAndCollectionQueries->personDashboardEventsPage(
            $personId,
            $search,
            $status,
            $perPage,
            $page,
        );
    }

    /**
     * @param  list<string>  $referenceIds
     * @param  array<int|string, mixed>  $venueEagerLoads
     */
    public function referenceEventsQuery(array $referenceIds, array $venueEagerLoads): EventBuilder
    {
        return $this->homepageAndCollectionQueries->referenceEventsQuery($referenceIds, $venueEagerLoads);
    }

    public function institutionSpeakerCount(string $institutionId): int
    {
        return $this->homepageAndCollectionQueries->institutionSpeakerCount($institutionId);
    }

    public function institutionEventStats(array $institutionIds): array
    {
        return $this->homepageAndCollectionQueries->institutionEventStats($institutionIds);
    }

    /**
     * @param  array<string, list<string>>  $referenceFamilies
     * @return array<string, int>
     */
    public function publicEventCountsForReferenceFamilies(array $referenceFamilies): array
    {
        return $this->homepageAndCollectionQueries->publicEventCountsForReferenceFamilies($referenceFamilies);
    }

    /**
     * @param  array<string, list<string>>  $referenceFamilies
     * @return array<string, array{next_event_id: ?string, next_event_slug: ?string, next_event_title: ?string, next_event_starts_at: ?string, upcoming_events_count: int}>
     */
    public function referenceDirectoryFacts(array $referenceFamilies): array
    {
        return $this->homepageAndCollectionQueries->referenceDirectoryFacts($referenceFamilies);
    }

    public function personDirectoryFacts(array $personIds): array
    {
        return $this->homepageAndCollectionQueries->personDirectoryFacts($personIds);
    }

    public function upcomingSpeakerPersonIds(): array
    {
        return $this->homepageAndCollectionQueries->upcomingSpeakerPersonIds();
    }

    public function venueEventCounts(array $venueIds): array
    {
        return $this->homepageAndCollectionQueries->venueEventCounts($venueIds);
    }

    public function forInstitution(
        string $institutionId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->homepageAndCollectionQueries->forInstitution($institutionId, $past, $perPage, $now);
    }

    public function forPerson(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->homepageAndCollectionQueries->forPerson($personId, $past, $perPage, $now);
    }

    public function forVenue(
        string $venueId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->homepageAndCollectionQueries->forVenue($venueId, $past, $perPage, $now);
    }

    public function forReferences(
        array $referenceIds,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->homepageAndCollectionQueries->forReferences($referenceIds, $past, $perPage, $now);
    }

    public function forSeries(
        string $seriesId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->homepageAndCollectionQueries->forSeries($seriesId, $past, $perPage, $now);
    }

    public function personParticipations(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->homepageAndCollectionQueries->personParticipations($personId, $past, $perPage, $now);
    }
}
