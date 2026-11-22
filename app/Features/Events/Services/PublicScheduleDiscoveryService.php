<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Api\EventSearchReader;
use App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf;
use App\Features\Discovery\Api\Orm\PublicScheduleOrmPort;
use App\Features\Discovery\Api\EventDiscoveryCriteriaBuilder;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\EventScheduleFact;
use App\Features\Events\Api\EventScheduleFactSet;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Events\Api\EventScheduleTimeFact;
use App\Features\Events\Models\Event;
use App\Features\Events\Queries\PostgresEventDiscovery;
use App\Features\Events\Support\PublicScheduleLeaf as PublicScheduleLeafValue;
use App\Features\Places\Api\Orm\PlaceLookup;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\PrayerOffset;
use App\Platform\Time\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final readonly class PublicScheduleDiscoveryService implements PublicScheduleOrmPort
{
    public function __construct(
        private PostgresEventDiscovery $scheduleEvents,
        private EventScheduleReads $scheduleFacts,
        private EventSearchReader $eventSearch,
        private SubjectFollows $subjectFollows,
        private PlaceLookup $placeLookup,
        private EventDiscoveryCriteriaBuilder $criteriaFactory,
    ) {}

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
            $directFactSet = $this->scheduleFacts->publicScheduleFactSet($criteria->text, $eventFilters);

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

        $leaves = collect($factSet->leaves)
            ->filter(fn (EventScheduleFact $fact): bool => $this->matchesFilters($fact, $criteria->filters))
            ->values();

        if ($sort === 'distance') {
            $leaves = $leaves->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByDistance($left, $right))->values();
        } elseif (($criteria->filters['time_scope'] ?? null) === 'past') {
            // Past listings read newest-first ("Terbaru"): the most recently
            // held majlis should lead, mirroring how upcoming listings lead
            // with the soonest one.
            $leaves = $leaves->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByTime($right, $left))->values();
        } else {
            $leaves = $leaves->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByTime($left, $right))->values();
        }

        return $this->paginateFacts($leaves, $perPage);
    }

    /** @return Collection<int, PublicScheduleModelLeaf> */
    public function homepageSelection(?string $accountId): Collection
    {
        $personIds = $accountId !== null ? $this->subjectFollows->followedSubjectIds($accountId, EngagementSubjectType::Person) : [];
        $institutionIds = $accountId !== null ? $this->subjectFollows->followedSubjectIds($accountId, EngagementSubjectType::Institution) : [];
        $referenceIds = $accountId !== null ? $this->subjectFollows->followedSubjectIds($accountId, EngagementSubjectType::Reference) : [];
        $hasFollows = $personIds !== [] || $institutionIds !== [] || $referenceIds !== [];

        $factSet = $this->scheduleFacts->homepageScheduleFacts($personIds, $institutionIds, $referenceIds);

        $followedEventIds = $hasFollows
            ? collect($this->scheduleFacts->homepageFollowedEventIds($personIds, $institutionIds, $referenceIds))->flip()
            : collect();

        $filters = [
            'time_scope' => 'upcoming',
            'starts_before' => UserDateTimeFormatter::userNow()->addDays(14)->toDateString(),
        ];
        $featuredFilters = ['time_scope' => 'upcoming'];
        $leaves = collect($factSet->leaves)
            ->unique(static fn (EventScheduleFact $fact): string => $fact->occurrenceId);

        $featured = $leaves
            ->filter(fn (EventScheduleFact $fact): bool => $this->matchesFilters($fact, $featuredFilters))
            ->filter(static fn (EventScheduleFact $fact): bool => $fact->isFeatured)
            ->sort(fn (EventScheduleFact $left, EventScheduleFact $right): int => $this->compareByTime($left, $right))
            ->take(9);

        $selected = $featured->concat(
            $leaves
                ->filter(fn (EventScheduleFact $fact): bool => $this->matchesFilters($fact, $filters))
                ->reject(static fn (EventScheduleFact $fact): bool => $fact->isFeatured)
                ->filter(fn (EventScheduleFact $fact): bool => $followedEventIds->has($fact->eventId))
                ->shuffle()
                ->take(9 - $featured->count()),
        )->values();

        return $this->hydrateLeaves($selected);
    }

    public function leafFor(Event $event, EventOccurrence $occurrence, iterable $sessions = []): PublicScheduleModelLeaf
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
     * @return Collection<int, PublicScheduleModelLeaf>
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

    /**
     * Overwrite package location venues with application Venue models for
     * one occurrence/session detail page.
     */
    public function hydrateOccurrencePageVenues(Event $event): void
    {
        $this->hydrateApplicationVenues(new EloquentCollection([$event]));
    }

    /**
     * Package event-location relations hydrate the package Venue class directly.
     * Public cards use the application Venue subclass because it owns address behavior.
     *
     * Only loaded relations are visited, so hydration never issues lazy
     * loads of its own; every visited location gets its venue relation set
     * (null included) so readers never lazy-load afterwards.
     *
     * @param  EloquentCollection<int, Event>  $events
     */
    private function hydrateApplicationVenues(EloquentCollection $events): void
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
            ->filter(fn (mixed $venueId): bool => is_string($venueId) && $venueId !== '')
            ->unique()
            ->values();

        if ($venueIds->isEmpty()) {
            return;
        }

        $this->placeLookup->hydrateCardVenues($locations);
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
     * @return LengthAwarePaginator<int, PublicScheduleModelLeaf>
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
     * @return Collection<int, PublicScheduleModelLeaf>
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
        $this->hydrateApplicationVenues($events);
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
}
