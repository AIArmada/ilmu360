<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Api\EventDiscoveryCriteriaBuilder;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\EventScheduleFact;
use App\Features\Events\Api\EventScheduleFactSet;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Api\EventScheduleTimeFact;
use App\Features\Events\Models\Event;
use App\Features\Events\Policies\PublicSchedulePolicy;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Events\Support\PublicScheduleLeaf as PublicScheduleLeafValue;
use App\Features\Events\Support\VenueAddressResolver;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\PrayerOffset;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final readonly class PublicScheduleDiscoveryService
{
    public function __construct(
        private PostgresEventDiscovery $scheduleEvents,
        private EventScheduleReads $scheduleFacts,
        private EventSearchReader $eventSearch,
        private SubjectFollows $subjectFollows,
        private VenueAddressResolver $venueAddresses,
        private EventDiscoveryCriteriaBuilder $criteriaFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PublicScheduleLeafValue>
     */
    public function search(
        ?string $query = null,
        array $filters = [],
        int $perPage = 12,
        string $sort = 'time',
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $radiusKm = null,
    ): LengthAwarePaginator {
        $criteria = $this->criteriaFactory->fromSearch($query, $filters, $perPage, $sort, $latitude, $longitude, $radiusKm);
        $eventFilters = $criteria->filters;

        foreach ([
            'starts_after',
            'starts_before',
            'starts_on_local_date',
            'time_scope',
            'prayer_time',
            'timing_mode',
            'starts_time_from',
            'starts_time_until',
            'has_end_time',
        ] as $filter) {
            unset($eventFilters[$filter]);
        }

        $eventFilters['time_scope'] = 'all';

        $currentPage = max(1, (int) Paginator::resolveCurrentPage());
        $hasExpressionFilters = $this->hasExpressionFilters($criteria->filters);

        if (
            $criteria->latitude === null
            && $criteria->longitude === null
            && $sort !== 'distance'
            && ! $hasExpressionFilters
        ) {
            $pageResult = $this->scheduleFacts->paginatePublicScheduleOccurrences(
                $criteria->text,
                $criteria->filters,
                $currentPage,
                $perPage,
                $sort,
            );

            if ($criteria->text !== null && ! $pageResult['has_matching_events']) {
                $parentPage = $this->eventSearch->search(
                    query: $criteria->text,
                    filters: $eventFilters,
                    perPage: 1000,
                    sort: $sort,
                );

                $factSet = $this->factsFromSearchPage($parentPage);
                $leaves = $this->sortLeaves(
                    collect($factSet->leaves)
                        ->filter(fn (EventScheduleFact $fact): bool => $this->matchesFilters($fact, $criteria->filters))
                        ->values(),
                    $sort,
                    $criteria->filters,
                );

                return $this->paginateFacts($leaves, $perPage);
            }

            return new Paginator(
                items: $this->hydrateSelectedOccurrences($pageResult['rows']),
                total: $pageResult['total'],
                perPage: $perPage,
                currentPage: $currentPage,
                options: [
                    'path' => Paginator::resolveCurrentPath(),
                    'pageName' => 'page',
                    'query' => request()->query(),
                ],
            );
        }

        if ($criteria->latitude !== null && $criteria->longitude !== null) {
            $parentPage = $this->eventSearch->searchNearbyWithQuery(
                query: $criteria->text,
                lat: $criteria->latitude,
                lng: $criteria->longitude,
                radiusKm: (int) ($criteria->radiusKm ?? 15),
                filters: $eventFilters,
                perPage: 1000,
            );

            $factSet = $this->factsFromSearchPage($parentPage);
        } else {
            $directFactSet = $hasExpressionFilters
                ? $this->scheduleFacts->publicScheduleFactSetForWindow($criteria->text, $eventFilters, $criteria->filters)
                : $this->scheduleFacts->publicScheduleFactSet($criteria->text, $eventFilters);

            if ($criteria->text === null || $directFactSet->eventIds !== []) {
                $factSet = $directFactSet;
            } else {
                $parentPage = $this->eventSearch->search(
                    query: $criteria->text,
                    filters: $eventFilters,
                    perPage: 1000,
                    sort: $sort,
                );

                $factSet = $this->factsFromSearchPage($parentPage);
            }
        }

        $leaves = $this->sortLeaves(
            collect($factSet->leaves)
                ->filter(fn (EventScheduleFact $fact): bool => $this->matchesFilters($fact, $criteria->filters))
                ->values(),
            $sort,
            $criteria->filters,
        );

        return $this->paginateFacts($leaves, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function hasExpressionFilters(array $filters): bool
    {
        foreach (['timing_mode', 'prayer_time', 'starts_time_from', 'starts_time_until'] as $key) {
            $value = $filters[$key] ?? null;

            if (is_array($value) ? $value !== [] : filled($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, EventScheduleFact>  $leaves
     * @param  array<string, mixed>  $filters
     * @return Collection<int, EventScheduleFact>
     */
    private function sortLeaves(Collection $leaves, string $sort, array $filters): Collection
    {
        if ($sort === 'distance') {
            return $leaves->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByDistance($left, $right))->values();
        }

        if (($filters['time_scope'] ?? null) === 'past') {
            // Past listings read newest-first ("Terbaru"): the most recently
            // held majlis should lead, mirroring how upcoming listings lead
            // with the soonest one.
            return $leaves->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByTime($right, $left))->values();
        }

        return $leaves->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByTime($left, $right))->values();
    }

    /** @return Collection<int, PublicScheduleLeafValue> */
    public function homepageSelection(?string $accountId): Collection
    {
        $featuredRows = $this->scheduleFacts->homepageFeaturedOccurrences(9);
        $selectedRows = $featuredRows;

        if ($accountId !== null && count($featuredRows) < 9) {
            $followsByType = $this->subjectFollows->followedSubjectIdsByTypes($accountId, [
                EngagementSubjectType::Person,
                EngagementSubjectType::Institution,
                EngagementSubjectType::Reference,
            ]);

            $personIds = $followsByType[EngagementSubjectType::Person->value] ?? [];
            $institutionIds = $followsByType[EngagementSubjectType::Institution->value] ?? [];
            $referenceIds = $followsByType[EngagementSubjectType::Reference->value] ?? [];

            if ($personIds !== [] || $institutionIds !== [] || $referenceIds !== []) {
                $startsBeforeUtc = UserDateTimeFormatter::parseUserDateToUtc(
                    UserDateTimeFormatter::userNow()->addDays(14)->toDateString(),
                    true,
                );

                if ($startsBeforeUtc instanceof CarbonInterface) {
                    $followedRows = $this->scheduleFacts->homepageFollowedOccurrences(
                        $personIds,
                        $institutionIds,
                        $referenceIds,
                        $startsBeforeUtc,
                        excludeOccurrenceIds: array_column($featuredRows, 'occurrence_id'),
                    );

                    if ($followedRows !== []) {
                        shuffle($followedRows);
                        $followedRows = array_slice($followedRows, 0, 9 - count($featuredRows));
                        $selectedRows = array_merge($featuredRows, $followedRows);
                    }
                }
            }
        }

        return $this->hydrateSelectedOccurrences($selectedRows);
    }

    /** @param iterable<int, EventSession> $sessions */
    public function leafFor(Event $event, EventOccurrence $occurrence, iterable $sessions = []): PublicScheduleLeafValue
    {
        $sessionModels = [];

        foreach ($sessions as $session) {
            if ($session instanceof EventSession) {
                $sessionModels[] = $session;
            }
        }

        return new PublicScheduleLeafValue($event, $occurrence, new Collection($sessionModels));
    }

    /**
     * @param  list<string>  $eventIds
     * @return Collection<int, PublicScheduleLeafValue>
     */
    public function leavesForEventIds(array $eventIds): Collection
    {
        $eventIds = array_values(array_unique($eventIds));
        $factSet = $this->scheduleFacts->scheduleFactsForEventIds($eventIds);
        $leaves = collect($factSet->leaves)
            ->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByTime($left, $right))
            ->values();

        return $this->hydrateLeaves($leaves, withOccurrenceReferences: true);
    }

    /** Prime scoped venue address projections; do not mutate Eloquent relations. */
    public function primeOccurrencePageVenueAddresses(Event $event): void
    {
        $this->primeVenueAddresses(new EloquentCollection([$event]));
    }

    /** @param EloquentCollection<int, Event> $events */
    private function primeVenueAddresses(EloquentCollection $events): void
    {
        /** @var Collection<int, EventLocation> $locations */
        $locations = collect();

        foreach ($events as $event) {
            if ($event->relationLoaded('primaryLocation')) {
                $primaryLocation = $event->getRelation('primaryLocation');

                if ($primaryLocation instanceof EventLocation) {
                    $locations->push($primaryLocation);
                }
            }

            if (! $event->relationLoaded('occurrences')) {
                continue;
            }

            foreach ($event->occurrences as $occurrence) {
                if ($occurrence->relationLoaded('locations')) {
                    foreach ($occurrence->locations as $location) {
                        $locations->push($location);
                    }
                }

                if (! $occurrence->relationLoaded('sessions')) {
                    continue;
                }

                foreach ($occurrence->sessions as $session) {
                    if (! $session->relationLoaded('locations')) {
                        continue;
                    }

                    foreach ($session->locations as $location) {
                        $locations->push($location);
                    }
                }
            }
        }

        $venueIds = $locations
            ->map(fn (EventLocation $location): mixed => $location->getAttribute('venue_id'))
            ->filter(fn (mixed $venueId): bool => is_string($venueId) && $venueId !== '');

        foreach ($events as $event) {
            $defaultVenueId = $event->getAttribute('default_venue_id');

            if (is_string($defaultVenueId) && $defaultVenueId !== '' && ! $event->relationLoaded('venue')) {
                $venueIds = $venueIds->push($defaultVenueId);
            }
        }

        $venueIds = $venueIds->unique()->values();

        if ($venueIds->isEmpty()) {
            return;
        }

        $this->venueAddresses->prime($venueIds->all());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(EventScheduleFact $fact, array $filters): bool
    {
        $startsAt = $fact->startsAt;
        $endsAt = $fact->endsAt;
        $now = UserDateTimeFormatter::userNow()->utc();
        $endInstant = $endsAt ?? $startsAt;
        $timeScope = is_string($filters['time_scope'] ?? null) ? $filters['time_scope'] : 'upcoming';

        if ($timeScope === 'past' && $endInstant->greaterThanOrEqualTo($now)) {
            return false;
        }

        if ($timeScope === 'upcoming' && $endInstant->lessThan($now)) {
            return false;
        }

        $startsAfter = UserDateTimeFormatter::parseUserDateToUtc($filters['starts_after'] ?? null);

        if ($startsAfter instanceof CarbonInterface && (
            $endsAt instanceof CarbonInterface
                ? $endsAt->lessThan($startsAfter)
                : $startsAt->lessThan($startsAfter)
        )) {
            return false;
        }

        $startsBefore = UserDateTimeFormatter::parseUserDateToUtc($filters['starts_before'] ?? null, true);

        if ($startsBefore instanceof CarbonInterface && $startsAt->greaterThan($startsBefore)) {
            return false;
        }

        $startsOnLocalDate = $filters['starts_on_local_date'] ?? null;

        if (is_string($startsOnLocalDate) && $startsOnLocalDate !== '') {
            $localStart = $startsAt->timezone(UserDateTimeFormatter::resolveTimezone())->toDateString();

            if ($localStart !== $startsOnLocalDate) {
                return false;
            }
        }

        $hasEndTime = $filters['has_end_time'] ?? null;

        if ($hasEndTime === true && ! $endsAt instanceof CarbonInterface) {
            return false;
        }

        if ($hasEndTime === false && $endsAt instanceof CarbonInterface) {
            return false;
        }

        $expressions = $this->timeExpressions($fact);
        $timingMode = $filters['timing_mode'] ?? null;

        if ($timingMode === 'prayer_relative' && ! collect($expressions)->contains(static fn (EventScheduleTimeFact $expression): bool => $expression->timeMode === 'prayer_relative')) {
            return false;
        }

        if ($timingMode === 'absolute' && collect($expressions)->contains(static fn (EventScheduleTimeFact $expression): bool => $expression->timeMode === 'prayer_relative')) {
            return false;
        }

        $prayerTimeFilter = $filters['prayer_time'] ?? null;
        $prayerTimes = is_array($prayerTimeFilter)
            ? $prayerTimeFilter
            : (is_string($prayerTimeFilter) ? [$prayerTimeFilter] : []);
        $prayerTimes = array_values(array_filter(
            $prayerTimes,
            static fn (mixed $prayerTime): bool => is_string($prayerTime) && trim($prayerTime) !== '',
        ));

        if ($prayerTimes !== [] && ! collect($expressions)->contains(
            fn (EventScheduleTimeFact $expression): bool => array_any(
                $prayerTimes,
                fn (mixed $prayerTime): bool => is_string($prayerTime) && $this->matchesPrayerTimeExpression($expression, $prayerTime),
            ),
        )) {
            return false;
        }

        $localTime = $startsAt->timezone(UserDateTimeFormatter::resolveTimezone())->format('H:i');
        $from = $this->normalizeTime($filters['starts_time_from'] ?? null);
        $until = $this->normalizeTime($filters['starts_time_until'] ?? null);

        if ($timingMode === 'absolute' && $from !== null && $until !== null) {
            $inside = $from <= $until
                ? $localTime >= $from && $localTime <= $until
                : $localTime >= $from || $localTime <= $until;

            if (! $inside) {
                return false;
            }
        } elseif ($timingMode === 'absolute' && $from !== null && $localTime < $from) {
            return false;
        } elseif ($timingMode === 'absolute' && $until !== null && $localTime > $until) {
            return false;
        }

        return true;
    }

    private function matchesPrayerTimeExpression(EventScheduleTimeFact $expression, string $prayerTime): bool
    {
        $normalizedPrayerTime = mb_strtolower(trim($prayerTime));
        $prayerTimeEnum = EventPrayerTime::tryFrom($normalizedPrayerTime);
        $displayLabel = mb_strtolower($expression->displayLabel);
        $anchorCode = $expression->anchorCode;

        if (! $prayerTimeEnum instanceof EventPrayerTime) {
            return str_contains($displayLabel, $normalizedPrayerTime)
                || str_contains(mb_strtolower($anchorCode), $normalizedPrayerTime);
        }

        $prayerReference = $prayerTimeEnum->toPrayerReference()?->value;

        if ($prayerReference === null) {
            return str_contains($displayLabel, mb_strtolower($prayerTimeEnum->getLabel()));
        }

        $offset = $prayerTimeEnum->getDefaultOffset();
        $relation = $offset instanceof PrayerOffset && $offset->minutes() < 0 ? 'before' : 'after';

        if ($expression->relation !== $relation) {
            return false;
        }

        return $anchorCode === $prayerReference
            || str_contains($displayLabel, mb_strtolower($prayerTimeEnum->getLabel()));
    }

    /** @return list<EventScheduleTimeFact> */
    private function timeExpressions(EventScheduleFact $fact): array
    {
        return $fact->occurrenceTimeExpressions !== []
            ? $fact->occurrenceTimeExpressions
            : $fact->eventTimeExpressions;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return now(UserDateTimeFormatter::resolveTimezone())
                ->setTimeFromTimeString($value)
                ->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function compareByDistance(EventScheduleFact $left, EventScheduleFact $right): int
    {
        $distanceResult = ($left->distanceKm ?? PHP_FLOAT_MAX)
            <=> ($right->distanceKm ?? PHP_FLOAT_MAX);

        return $distanceResult !== 0
            ? $distanceResult
            : $this->compareByTime($left, $right);
    }

    private function compareByTime(EventScheduleFact $left, EventScheduleFact $right): int
    {
        $startResult = $left->startsAt->getTimestamp()
            <=> $right->startsAt->getTimestamp();

        if ($startResult !== 0) {
            return $startResult;
        }

        $titleResult = $left->title() <=> $right->title();

        return $titleResult !== 0 ? $titleResult : $left->occurrenceId <=> $right->occurrenceId;
    }

    /**
     * Convert an immutable event search result into schedule facts.
     */
    private function factsFromSearchPage(EventSearchPage $page): EventScheduleFactSet
    {
        $eventIds = array_map(
            static fn (EventListData $item): string => $item->id,
            $page->items,
        );

        return $this->scheduleFacts->scheduleFactsForEventIds($eventIds, $page->distances);
    }

    /**
     * @param  Collection<int, EventScheduleFact>  $facts
     * @return LengthAwarePaginator<int, PublicScheduleLeafValue>
     */
    private function paginateFacts(Collection $facts, int $perPage): LengthAwarePaginator
    {
        $currentPage = Paginator::resolveCurrentPage();
        $selectedFacts = $facts->forPage($currentPage, $perPage)->values();
        $items = $this->hydrateLeaves($selectedFacts);

        return new Paginator(
            items: $items,
            total: $facts->count(),
            perPage: $perPage,
            currentPage: $currentPage,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Hydrate only facts selected for a page or explicit model-bound section.
     *
     * @param  Collection<int, EventScheduleFact>  $facts
     * @return Collection<int, PublicScheduleLeafValue>
     */
    private function hydrateLeaves(Collection $facts, bool $withOccurrenceReferences = false): Collection
    {
        if ($facts->isEmpty()) {
            return collect();
        }

        $eventIds = $facts
            ->map(static fn (EventScheduleFact $fact): string => $fact->eventId)
            ->unique()
            ->values()
            ->all();
        $distanceByEventId = $facts
            ->filter(static fn (EventScheduleFact $fact): bool => $fact->distanceKm !== null)
            ->mapWithKeys(static fn (EventScheduleFact $fact): array => [$fact->eventId => $fact->distanceKm]);
        $events = $this->scheduleEvents->hydrateScheduleEvents(
            $eventIds,
            withOccurrenceReferences: $withOccurrenceReferences,
            distanceByEventId: $distanceByEventId->all(),
        );
        $this->primeVenueAddresses($events);
        $eventsById = $events->keyBy(static fn (Event $event): string => (string) $event->getKey());
        $leaves = collect();

        foreach ($facts as $fact) {
            $event = $eventsById->get($fact->eventId);

            if (! $event instanceof Event || ! $event->relationLoaded('occurrences')) {
                continue;
            }

            $occurrence = $event->occurrences->first(
                static fn (EventOccurrence $candidate): bool => (string) $candidate->getKey() === $fact->occurrenceId,
            );

            if (! $occurrence instanceof EventOccurrence || ! $occurrence->relationLoaded('sessions')) {
                continue;
            }

            $selectedSessionIds = array_fill_keys($fact->sessionIds, true);
            $sessions = $occurrence->sessions
                ->filter(static fn (EventSession $session): bool => isset($selectedSessionIds[(string) $session->getKey()]))
                ->values();

            $leaves->push($this->leafFor($event, $occurrence, $sessions));
        }

        return $leaves;
    }

    /**
     * Hydrate exactly the selected occurrence rows, constraining both the
     * parent ids and the occurrence ids loaded per event.
     *
     * @param  list<array{event_id: string, occurrence_id: string}>  $rows
     * @return Collection<int, PublicScheduleLeafValue>
     */
    private function hydrateSelectedOccurrences(array $rows): Collection
    {
        if ($rows === []) {
            return collect();
        }

        $eventIds = array_values(array_unique(array_map(static fn (array $row): string => $row['event_id'], $rows)));
        $occurrenceIds = array_values(array_unique(array_map(static fn (array $row): string => $row['occurrence_id'], $rows)));

        $events = $this->scheduleEvents->hydrateScheduleEvents(
            $eventIds,
            occurrenceIds: $occurrenceIds,
        );
        $this->primeVenueAddresses($events);
        $eventsById = $events->keyBy(static fn (Event $event): string => (string) $event->getKey());
        $leaves = collect();

        foreach ($rows as $row) {
            $event = $eventsById->get($row['event_id']);

            if (! $event instanceof Event || ! $event->relationLoaded('occurrences')) {
                continue;
            }

            $occurrence = $event->occurrences->first(
                static fn (EventOccurrence $candidate): bool => (string) $candidate->getKey() === $row['occurrence_id'],
            );

            if (! $occurrence instanceof EventOccurrence || ! $occurrence->relationLoaded('sessions')) {
                continue;
            }

            $sessions = $occurrence->sessions
                ->filter(static fn (EventSession $session): bool => PublicSchedulePolicy::isMeaningfulSession($session))
                ->values();

            $leaves->push($this->leafFor($event, $occurrence, $sessions));
        }

        return $leaves->values();
    }
}
