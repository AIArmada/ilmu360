<?php

declare(strict_types=1);

namespace App\Features\Discovery\Services;

use AIArmada\Events\Models\EventLocation;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Discovery\Api\Data\EventListData;
use App\Features\Discovery\Api\Data\EventSearchPage;
use App\Features\Discovery\Api\Orm\PublicScheduleModelLeaf;
use App\Features\Discovery\Api\Orm\PublicScheduleOrmPort;
use App\Features\Discovery\Support\PublicScheduleLeaf as PublicScheduleLeafValue;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Events\Api\Orm\PublicScheduleEligibility;
use App\Features\Events\Api\Orm\PublicScheduleEventReads;
use App\Features\Events\Models\Event;
use App\Features\Places\Api\Orm\PlaceLookup;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\PrayerOffset;
use App\Models\User;
use App\Platform\Time\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final readonly class PublicScheduleDiscoveryService implements PublicScheduleOrmPort
{
    public function __construct(
        private PublicScheduleEventReads $scheduleEvents,
        private EventDiscoveryCriteriaFactory $criteriaFactory = new EventDiscoveryCriteriaFactory,
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
            $parentPage = app(EventSearchService::class)->searchNearbyWithQuery(
                query: $criteria->text,
                lat: $criteria->latitude,
                lng: $criteria->longitude,
                radiusKm: (int) ($criteria->radiusKm ?? 15),
                filters: $eventFilters,
                perPage: 1000,
            );

            $events = $this->eventsFromSearchPage($parentPage);
        } else {
            $relations = $this->scheduleEvents->publicScheduleCardRelations();

            if ($criteria->text === null) {
                /** @var EloquentCollection<int, Event> $events */
                $events = $this->scheduleEvents->publicScheduleEvents(null, $eventFilters, $relations);
            } else {
                $directEvents = $this->scheduleEvents->publicScheduleEvents($criteria->text, $eventFilters, $relations);

                if ($directEvents->isNotEmpty()) {
                    /** @var EloquentCollection<int, Event> $events */
                    $events = $directEvents;
                } else {
                    $parentPage = app(EventSearchService::class)->search(
                        query: $criteria->text,
                        filters: $eventFilters,
                        perPage: 1000,
                        sort: $sort,
                    );

                    $events = $this->eventsFromSearchPage($parentPage);
                }
            }
        }

        $this->hydrateApplicationVenues($events);

        $leaves = $this->flatten($events)
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => $this->matchesFilters($leaf, $criteria->filters))
            ->values();

        if ($sort === 'distance') {
            $leaves = $leaves->sort(fn (PublicScheduleModelLeaf $left, PublicScheduleModelLeaf $right): int => $this->compareByDistance($left, $right))->values();
        } elseif (($criteria->filters['time_scope'] ?? null) === 'past') {
            // Past listings read newest-first ("Terbaru"): the most recently
            // held majlis should lead, mirroring how upcoming listings lead
            // with the soonest one.
            $leaves = $leaves->sort(fn (PublicScheduleModelLeaf $left, PublicScheduleModelLeaf $right): int => $this->compareByTime($right, $left))->values();
        } else {
            $leaves = $leaves->sort(fn (PublicScheduleModelLeaf $left, PublicScheduleModelLeaf $right): int => $this->compareByTime($left, $right))->values();
        }

        return $this->paginate($leaves, $perPage);
    }

    /** @return Collection<int, PublicScheduleModelLeaf> */
    public function homepageSelection(?User $user): Collection
    {
        $engagement = app(SubjectFollows::class);
        $accountId = $user?->getKey() !== null ? (string) $user->getKey() : null;
        $personIds = $accountId !== null ? $engagement->followedSubjectIds($accountId, EngagementSubjectType::Person) : [];
        $institutionIds = $accountId !== null ? $engagement->followedSubjectIds($accountId, EngagementSubjectType::Institution) : [];
        $referenceIds = $accountId !== null ? $engagement->followedSubjectIds($accountId, EngagementSubjectType::Reference) : [];
        $hasFollows = $personIds !== [] || $institutionIds !== [] || $referenceIds !== [];

        $relations = $this->scheduleEvents->publicScheduleCardRelations();
        $events = $this->scheduleEvents->homepageScheduleEvents($personIds, $institutionIds, $referenceIds, $relations);

        $followedEventIds = $hasFollows
            ? collect($this->scheduleEvents->homepageFollowedEventIds($personIds, $institutionIds, $referenceIds))->flip()
            : collect();

        $this->hydrateApplicationVenues($events);
        $filters = [
            'time_scope' => 'upcoming',
            'starts_before' => UserDateTimeFormatter::userNow()->addDays(14)->toDateString(),
        ];
        $featuredFilters = ['time_scope' => 'upcoming'];
        $leaves = $this->flatten($events)
            ->unique(fn (PublicScheduleModelLeaf $leaf): string => $leaf->id());

        $featured = $leaves
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => $this->matchesFilters($leaf, $featuredFilters))
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => (bool) $this->occurrenceOf($leaf)->is_featured)
            ->sort(fn (PublicScheduleModelLeaf $left, PublicScheduleModelLeaf $right): int => $this->compareByTime($left, $right))
            ->take(9);

        return $featured->concat(
            $leaves
                ->filter(fn (PublicScheduleModelLeaf $leaf): bool => $this->matchesFilters($leaf, $filters))
                ->reject(fn (PublicScheduleModelLeaf $leaf): bool => (bool) $this->occurrenceOf($leaf)->is_featured)
                ->filter(fn (PublicScheduleModelLeaf $leaf): bool => $followedEventIds->has($this->eventOf($leaf)->id))
                ->shuffle()
                ->take(9 - $featured->count()),
        )->values();
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
     * @param  iterable<int, Event>  $events
     * @return Collection<int, PublicScheduleModelLeaf>
     */
    public function leavesForEvents(iterable $events): Collection
    {
        /** @var EloquentCollection<int, Event> $eventCollection */
        $eventCollection = $events instanceof EloquentCollection
            ? $events
            : EloquentCollection::make($events);

        $this->scheduleEvents->loadScheduleGraph($eventCollection, withOccurrenceReferences: true);
        $this->hydrateApplicationVenues($eventCollection);

        return $this->flatten($eventCollection)
            ->filter(fn (PublicScheduleModelLeaf $leaf): bool => $leaf->startsAt() instanceof CarbonInterface)
            ->sort(fn (PublicScheduleModelLeaf $left, PublicScheduleModelLeaf $right): int => $this->compareByTime($left, $right))
            ->values();
    }

    /**
     * @param  EloquentCollection<int, Event>  $events
     * @return Collection<int, PublicScheduleModelLeaf>
     */
    private function flatten(EloquentCollection $events): Collection
    {
        $leaves = collect();
        $eligibility = app(PublicScheduleEligibility::class);

        foreach ($events as $event) {
            foreach ($event->occurrences as $occurrence) {
                if (! $eligibility->isPublicOccurrence($occurrence)) {
                    continue;
                }

                $sessions = $occurrence->sessions
                    ->filter(fn (EventSession $session): bool => $eligibility->isMeaningfulSession($session))
                    ->values();

                $leaves->push(new PublicScheduleLeafValue($event, $occurrence, $sessions));
            }
        }

        return $leaves;
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

        app(PlaceLookup::class)->hydrateCardVenues($locations);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(PublicScheduleModelLeaf $leaf, array $filters): bool
    {
        $startsAt = $leaf->startsAt();

        if (! $startsAt instanceof CarbonInterface) {
            return false;
        }

        $endsAt = $leaf->endsAt();
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
            $localStart = $startsAt->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->toDateString();

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

        $expressions = $this->timeExpressions($leaf);
        $timingMode = $filters['timing_mode'] ?? null;

        if ($timingMode === 'prayer_relative' && ! $expressions->contains(fn (mixed $expression): bool => $expression->time_mode === 'prayer_relative')) {
            return false;
        }

        if ($timingMode === 'absolute' && $expressions->contains(fn (mixed $expression): bool => $expression->time_mode === 'prayer_relative')) {
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

        if ($prayerTimes !== [] && ! $expressions->contains(fn (mixed $expression): bool => array_any($prayerTimes, fn ($prayerTime) => is_string($prayerTime) && $this->matchesPrayerTimeExpression($expression, $prayerTime)))) {
            return false;
        }

        $localTime = $startsAt->copy()->timezone(UserDateTimeFormatter::resolveTimezone())->format('H:i');
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

    private function matchesPrayerTimeExpression(mixed $expression, string $prayerTime): bool
    {
        $normalizedPrayerTime = mb_strtolower(trim($prayerTime));
        $prayerTimeEnum = EventPrayerTime::tryFrom($normalizedPrayerTime);
        $displayLabel = mb_strtolower((string) $expression->display_label);
        $anchorCode = (string) $expression->anchor_code;

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

        if ((string) $expression->relation !== $relation) {
            return false;
        }

        return $anchorCode === $prayerReference
            || str_contains($displayLabel, mb_strtolower($prayerTimeEnum->getLabel()));
    }

    /** @return Collection<int, mixed> */
    private function timeExpressions(PublicScheduleModelLeaf $leaf): Collection
    {
        $occurrence = $this->occurrenceOf($leaf);

        if ($occurrence->timeExpressions->isNotEmpty()) {
            return $occurrence->timeExpressions;
        }

        return $this->eventOf($leaf)->timeExpressions;
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

    private function compareByDistance(PublicScheduleModelLeaf $left, PublicScheduleModelLeaf $right): int
    {
        $leftDistance = $this->eventOf($left)->getAttribute('distance_km');
        $rightDistance = $this->eventOf($right)->getAttribute('distance_km');
        $distanceResult = ((float) (is_numeric($leftDistance) ? $leftDistance : PHP_FLOAT_MAX))
            <=> ((float) (is_numeric($rightDistance) ? $rightDistance : PHP_FLOAT_MAX));

        return $distanceResult !== 0
            ? $distanceResult
            : $this->compareByTime($left, $right);
    }

    private function compareByTime(PublicScheduleModelLeaf $left, PublicScheduleModelLeaf $right): int
    {
        $startResult = ($left->startsAt()?->getTimestamp() ?? PHP_INT_MAX)
            <=> ($right->startsAt()?->getTimestamp() ?? PHP_INT_MAX);

        if ($startResult !== 0) {
            return $startResult;
        }

        $titleResult = $left->title() <=> $right->title();

        return $titleResult !== 0 ? $titleResult : $left->id() <=> $right->id();
    }

    /**
     * Rehydrate the page ids into models with the schedule eager loads.
     *
     * @return EloquentCollection<int, Event>
     */
    private function eventsFromSearchPage(EventSearchPage $page): EloquentCollection
    {
        $eventIds = array_map(
            static fn (EventListData $item): string => $item->id,
            $page->items,
        );

        if ($eventIds === []) {
            return new EloquentCollection;
        }

        return $this->scheduleEvents->hydrateScheduleEvents($eventIds);
    }

    /**
     * @param  Collection<int, PublicScheduleModelLeaf>  $items
     * @return LengthAwarePaginator<int, PublicScheduleModelLeaf>
     */
    private function paginate(Collection $items, int $perPage): LengthAwarePaginator
    {
        $currentPage = Paginator::resolveCurrentPage();

        return new Paginator(
            items: $items->forPage($currentPage, $perPage)->values(),
            total: $items->count(),
            perPage: $perPage,
            currentPage: $currentPage,
            options: [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
                'query' => request()->query(),
            ],
        );
    }

    private function occurrenceOf(PublicScheduleModelLeaf $leaf): EventOccurrence
    {
        return $leaf->occurrence();
    }

    private function eventOf(PublicScheduleModelLeaf $leaf): Event
    {
        return $leaf->event();
    }
}
