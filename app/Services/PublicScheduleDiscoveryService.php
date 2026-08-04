<?php

declare(strict_types=1);

namespace App\Services;

use AIArmada\Events\Contracts\EventSearchRelationProvider;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Data\EventDiscoveryCriteriaFactory;
use App\Data\PublicScheduleLeaf;
use App\Enums\EventPrayerTime;
use App\Models\Event;
use App\Support\Events\PublicSchedulePolicy;
use App\Support\Events\PublicScheduleSlug;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class PublicScheduleDiscoveryService
{
    public function __construct(
        private readonly PostgresEventDiscovery $eventDiscovery,
        private readonly EventSearchRelationProvider $relationProvider,
        private readonly EventDiscoveryCriteriaFactory $criteriaFactory = new EventDiscoveryCriteriaFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PublicScheduleLeaf>
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
            /** @var LengthAwarePaginator<int, Event> $parentPaginator */
            $parentPaginator = app(EventSearchService::class)->searchNearbyWithQuery(
                query: $criteria->text,
                lat: $criteria->latitude,
                lng: $criteria->longitude,
                radiusKm: (int) ($criteria->radiusKm ?? 15),
                filters: $eventFilters,
                perPage: 1000,
            );

            /** @var EloquentCollection<int, Event> $events */
            $events = new EloquentCollection($parentPaginator->items());
            $events->load($this->scheduleRelations());
        } else {
            if ($criteria->text === null) {
                /** @var EloquentCollection<int, Event> $events */
                $events = $this->eventDiscovery
                    ->publicEventQuery(null, $eventFilters)
                    ->with($this->scheduleRelations())
                    ->get();
            } else {
                $directEvents = $this->eventDiscovery
                    ->publicEventQuery($criteria->text, $eventFilters)
                    ->with($this->scheduleRelations())
                    ->get();

                if ($directEvents->isNotEmpty()) {
                    /** @var EloquentCollection<int, Event> $events */
                    $events = $directEvents;
                } else {
                    /** @var LengthAwarePaginator<int, Event> $parentPaginator */
                    $parentPaginator = app(EventSearchService::class)->search(
                        query: $criteria->text,
                        filters: $eventFilters,
                        perPage: 1000,
                        sort: $sort,
                    );

                    /** @var EloquentCollection<int, Event> $events */
                    $events = new EloquentCollection($parentPaginator->items());
                    $events->load($this->scheduleRelations());
                }
            }
        }

        $leaves = $this->flatten($events)
            ->filter(fn (PublicScheduleLeaf $leaf): bool => $this->matchesFilters($leaf, $criteria->filters))
            ->values();

        if ($sort === 'distance') {
            $leaves = $leaves->sort(fn (PublicScheduleLeaf $left, PublicScheduleLeaf $right): int => $this->compareByDistance($left, $right))->values();
        } else {
            $leaves = $leaves->sort(fn (PublicScheduleLeaf $left, PublicScheduleLeaf $right): int => $this->compareByTime($left, $right))->values();
        }

        return $this->paginate($leaves, $perPage);
    }

    public function findOccurrence(Event $event, string $slug): ?EventOccurrence
    {
        $event->loadMissing('occurrences');

        /** @var EventOccurrence|null $occurrence */
        $occurrence = $event->occurrences->first(
            fn (EventOccurrence $candidate): bool => PublicScheduleSlug::occurrence($candidate) === $slug,
        );

        return $occurrence;
    }

    public function findSession(EventOccurrence $occurrence, string $slug): ?EventSession
    {
        $occurrence->loadMissing('sessions');

        /** @var EventSession|null $session */
        $session = $occurrence->sessions->first(
            fn (EventSession $candidate): bool => PublicScheduleSlug::session($candidate) === $slug,
        );

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicRelations(): array
    {
        return $this->scheduleRelations();
    }

    /**
     * @param  EloquentCollection<int, Event>  $events
     * @return Collection<int, PublicScheduleLeaf>
     */
    private function flatten(EloquentCollection $events): Collection
    {
        $leaves = collect();

        foreach ($events as $event) {
            foreach ($event->occurrences as $occurrence) {
                if (! PublicSchedulePolicy::isPublicOccurrence($occurrence)) {
                    continue;
                }

                $sessions = $occurrence->sessions
                    ->filter(fn (EventSession $session): bool => PublicSchedulePolicy::isMeaningfulSession($session))
                    ->values();

                if ($sessions->isEmpty()) {
                    $leaves->push(new PublicScheduleLeaf($event, $occurrence));

                    continue;
                }

                foreach ($sessions as $session) {
                    $leaves->push(new PublicScheduleLeaf($event, $occurrence, $session));
                }
            }
        }

        return $leaves;
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleRelations(): array
    {
        $publicScheduleScope = function (Relation $query): void {
            $query
                ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
        };

        return [
            ...$this->relationProvider->relations(),
            'occurrences' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.media',
            'occurrences.timeExpressions',
            'occurrences.locations.venue',
            'occurrences.locations.venueSpace',
            'occurrences.sessions' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('sort_order')
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.sessions.media',
            'occurrences.sessions.timeExpressions',
            'occurrences.sessions.locations.venue',
            'occurrences.sessions.locations.venueSpace',
            'occurrences.sessions.involvements' => fn (Relation $query) => $query
                ->where('status', 'active')
                ->where('visibility', 'public'),
            'occurrences.sessions.involvements.involveable',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(PublicScheduleLeaf $leaf, array $filters): bool
    {
        $startsAt = $leaf->startsAt();

        if (! $startsAt instanceof CarbonInterface) {
            return false;
        }

        $endsAt = $leaf->endsAt();
        $now = UserDateTimeFormatter::userNow()->utc();
        $timeScope = is_string($filters['time_scope'] ?? null) ? $filters['time_scope'] : 'upcoming';

        if ($timeScope === 'past' && $startsAt->greaterThanOrEqualTo($now)) {
            return false;
        }

        if ($timeScope === 'upcoming' && (
            $endsAt instanceof CarbonInterface
                ? $endsAt->lessThan($now)
                : $startsAt->lessThan($now)
        )) {
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

        $prayerTime = is_string($filters['prayer_time'] ?? null) ? mb_strtolower($filters['prayer_time']) : null;
        $prayerTimeEnum = is_string($filters['prayer_time'] ?? null)
            ? EventPrayerTime::tryFrom($filters['prayer_time'])
            : null;
        $prayerReference = $prayerTimeEnum?->toPrayerReference()?->value;

        if ($prayerTime !== null && $prayerTime !== '' && ! $expressions->contains(function (mixed $expression) use ($prayerTime): bool {
            return str_contains(mb_strtolower((string) $expression->display_label), $prayerTime)
                || str_contains(mb_strtolower((string) $expression->anchor_code), $prayerTime);
        }) && ! $expressions->contains(function (mixed $expression) use ($prayerTimeEnum, $prayerReference): bool {
            return $prayerTimeEnum instanceof EventPrayerTime
                && (
                    mb_strtolower((string) $expression->display_label) === mb_strtolower($prayerTimeEnum->getLabel())
                    || ($prayerReference !== null && (string) $expression->anchor_code === $prayerReference)
                );
        })) {
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

    /** @return Collection<int, mixed> */
    private function timeExpressions(PublicScheduleLeaf $leaf): Collection
    {
        if ($leaf->session?->timeExpressions?->isNotEmpty()) {
            return $leaf->session->timeExpressions;
        }

        if ($leaf->occurrence->timeExpressions->isNotEmpty()) {
            return $leaf->occurrence->timeExpressions;
        }

        return $leaf->event->timeExpressions;
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

    private function compareByDistance(PublicScheduleLeaf $left, PublicScheduleLeaf $right): int
    {
        $leftDistance = $left->event->getAttribute('distance_km');
        $rightDistance = $right->event->getAttribute('distance_km');
        $distanceResult = ((float) (is_numeric($leftDistance) ? $leftDistance : PHP_FLOAT_MAX))
            <=> ((float) (is_numeric($rightDistance) ? $rightDistance : PHP_FLOAT_MAX));

        return $distanceResult !== 0
            ? $distanceResult
            : $this->compareByTime($left, $right);
    }

    private function compareByTime(PublicScheduleLeaf $left, PublicScheduleLeaf $right): int
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
     * @param  Collection<int, PublicScheduleLeaf>  $items
     * @return LengthAwarePaginator<int, PublicScheduleLeaf>
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
}
