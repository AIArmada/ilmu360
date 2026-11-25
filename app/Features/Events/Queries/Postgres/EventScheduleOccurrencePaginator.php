<?php

declare(strict_types=1);

namespace App\Features\Events\Queries\Postgres;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\Models\EventTimeExpression;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Api\EventScheduleFact;
use App\Features\Events\Api\EventScheduleFactSet;
use App\Features\Events\Api\EventScheduleTimeFact;
use App\Features\Events\Models\Event;
use App\Features\Events\Policies\PublicSchedulePolicy;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\PrayerOffset;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Internal occurrence-level schedule paginator and schedule-fact projector.
 */
final readonly class EventScheduleOccurrencePaginator
{
    public function __construct(
        private EventFilterQueryBuilder $filterQueryBuilder,
        private EventScheduleHydrator $scheduleHydrator,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function publicScheduleFactSet(?string $query, array $filters): EventScheduleFactSet
    {
        /** @var EloquentCollection<int, Event> $events */
        $events = $this->filterQueryBuilder->buildPublicEventQuery($query, $filters)
            ->with($this->scheduleHydrator->scheduleFactRelations())
            ->get();

        return $this->scheduleFactSet($events);
    }

    /**
     * @param  array<string, mixed>  $parentFilters
     * @param  array<string, mixed>  $timeFilters
     */
    public function publicScheduleFactSetForWindow(?string $query, array $parentFilters, array $timeFilters): EventScheduleFactSet
    {
        $occurrencesTable = (new EventOccurrence)->getTable();
        $publicScheduleScope = $this->scheduleHydrator->constrainPublicSchedule(...);

        /** @var EloquentCollection<int, Event> $events */
        $events = $this->filterQueryBuilder->buildPublicEventQuery($query, $parentFilters)
            ->whereHas('occurrences', function (Builder $occurrences) use ($timeFilters, $occurrencesTable): void {
                $occurrences
                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
                $this->applyOccurrenceWindowFilters($occurrences, $timeFilters, $occurrencesTable);
            })
            ->with([
                'timeExpressions',
                'occurrences' => function (Relation $relation) use ($publicScheduleScope, $timeFilters, $occurrencesTable): void {
                    $publicScheduleScope($relation);
                    $this->applyOccurrenceWindowFilters($relation->getQuery(), $timeFilters, $occurrencesTable);
                    $relation
                        ->orderBy('starts_at')
                        ->orderBy('created_at')
                        ->orderBy('id');
                },
                'occurrences.timeExpressions',
                'occurrences.sessions' => function (Relation $relation) use ($publicScheduleScope): void {
                    $publicScheduleScope($relation);
                    $relation
                        ->orderBy('sort_order')
                        ->orderBy('starts_at')
                        ->orderBy('created_at')
                        ->orderBy('id');
                },
                'occurrences.sessions.timeExpressions',
            ])
            ->get();

        return $this->scheduleFactSet($events);
    }

    /**
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     */
    public function scheduleFactsForEventIds(array $eventIds, array $distanceByEventId = []): EventScheduleFactSet
    {
        $eventIds = array_values(array_unique($eventIds));

        if ($eventIds === []) {
            return new EventScheduleFactSet([], []);
        }

        /** @var EloquentCollection<string, Event> $eventsById */
        $eventsById = $this->scheduleHydrator->constrainCurrentPublicEventState(
            Event::query()
                ->with($this->scheduleHydrator->scheduleFactRelations())
                ->whereKey($eventIds),
        )
            ->get()
            ->keyBy(static fn (Event $event): string => (string) $event->getKey());

        $events = collect($eventIds)
            ->map(static fn (string $eventId): ?Event => $eventsById->get($eventId))
            ->filter(static fn (mixed $event): bool => $event instanceof Event)
            ->values();

        /** @var EloquentCollection<int, Event> $orderedEvents */
        $orderedEvents = new EloquentCollection($events->all());

        return $this->scheduleFactSet($orderedEvents, $distanceByEventId);
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
    ): array {
        $occurrencesTable = (new EventOccurrence)->getTable();
        $parentFilters = $filters;

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
        ] as $occurrenceTimeKey) {
            unset($parentFilters[$occurrenceTimeKey]);
        }

        $parentFilters['time_scope'] = 'all';

        $parentEventQuery = $this->filterQueryBuilder->buildPublicEventQuery($query, $parentFilters)->select('events.id');
        $timeScope = $this->filterQueryBuilder->normalizeTimeScope($filters['time_scope'] ?? null);

        $occurrenceQuery = DB::table("{$occurrencesTable} as occ")
            ->join('events as ev', 'ev.id', '=', 'occ.event_id')
            ->whereIn('occ.event_id', $parentEventQuery->toBase())
            ->whereIn('occ.status', $this->stringValues(Event::PUBLIC_SCHEDULE_STATUSES))
            ->whereIn('occ.visibility', $this->stringValues(Event::PUBLIC_SCHEDULE_VISIBILITIES))
            ->whereNotNull('occ.starts_at');

        $this->applyOccurrenceWindowFilters($occurrenceQuery, $filters, 'occ');
        $this->applyOccurrenceTimeExpressionFilters($occurrenceQuery, $filters, 'occ');

        $titleExpression = "trim(coalesce(nullif(trim(coalesce(occ.title, '')), ''), ev.title))";
        $direction = $timeScope === 'past' ? 'desc' : 'asc';

        $occurrenceQuery
            ->orderBy('occ.starts_at', $direction)
            ->orderByRaw("{$titleExpression} {$direction}")
            ->orderBy('occ.id', $direction);

        $rows = (clone $occurrenceQuery)
            ->select('occ.event_id', 'occ.id as occurrence_id')
            ->selectRaw('count(*) over () as matching_total')
            ->forPage(max(1, $page), max(1, $perPage))
            ->get();

        $firstRow = $rows->first();
        $total = $firstRow !== null ? (int) $firstRow->matching_total : 0;

        if ($firstRow === null && $page > 1) {
            $total = (int) (clone $occurrenceQuery)->count();
        }

        $hasMatchingEvents = $total > 0
            || $this->filterQueryBuilder->normalizeSearchQuery($query) === null
            || (clone $parentEventQuery)->exists();

        return [
            'rows' => $rows
                ->map(static fn (object $row): array => [
                    'event_id' => (string) $row->event_id,
                    'occurrence_id' => (string) $row->occurrence_id,
                ])
                ->values()
                ->all(),
            'total' => $total,
            'has_matching_events' => $hasMatchingEvents,
        ];
    }

    /**
     * Project owner-scoped event models into immutable schedule facts.
     *
     * @param  EloquentCollection<int, Event>  $events
     * @param  array<string, float>  $distanceByEventId
     */
    public function scheduleFactSet(EloquentCollection $events, array $distanceByEventId = []): EventScheduleFactSet
    {
        $eventIds = [];
        $leaves = [];

        foreach ($events as $event) {
            if (! $this->isCurrentlyPublicParentEvent($event)) {
                continue;
            }

            $eventId = (string) $event->getKey();
            $eventIds[] = $eventId;

            $distance = $distanceByEventId[$eventId] ?? ($event->getAttributes()['distance_km'] ?? null);
            $distanceKm = is_numeric($distance) ? (float) $distance : null;
            $eventTimeFacts = $this->scheduleTimeFacts($event->timeExpressions);

            foreach ($event->occurrences as $occurrence) {
                if (! PublicSchedulePolicy::isPublicOccurrence($occurrence)) {
                    continue;
                }

                $startsAt = $occurrence->getAttribute('starts_at');

                if (! $startsAt instanceof CarbonInterface) {
                    continue;
                }

                $endsAt = $occurrence->getAttribute('ends_at');
                $sessionIds = [];

                foreach ($occurrence->sessions as $session) {
                    if (PublicSchedulePolicy::isMeaningfulSession($session)) {
                        $sessionIds[] = (string) $session->getKey();
                    }
                }

                $leaves[] = new EventScheduleFact(
                    eventId: $eventId,
                    eventTitle: (string) $event->title,
                    eventStatus: (string) $event->status,
                    occurrenceId: (string) $occurrence->getKey(),
                    occurrenceTitle: (string) $occurrence->title,
                    startsAt: CarbonImmutable::instance($startsAt),
                    endsAt: $endsAt instanceof CarbonInterface ? CarbonImmutable::instance($endsAt) : null,
                    occurrenceTimeExpressions: $this->scheduleTimeFacts($occurrence->timeExpressions),
                    eventTimeExpressions: $eventTimeFacts,
                    sessionIds: $sessionIds,
                    isFeatured: (bool) $occurrence->is_featured,
                    distanceKm: $distanceKm,
                );
            }
        }

        return new EventScheduleFactSet($eventIds, $leaves);
    }

    /**
     * @param  Builder<*>|QueryBuilder  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyOccurrenceWindowFilters(Builder|QueryBuilder $query, array $filters, string $occurrencesTable): void
    {
        $endInstant = "coalesce({$occurrencesTable}.ends_at, {$occurrencesTable}.starts_at)";
        $timeScope = $this->filterQueryBuilder->normalizeTimeScope($filters['time_scope'] ?? null);
        $nowUtc = UserDateTimeFormatter::userNow()->utc();

        if ($timeScope === 'past') {
            $query->whereRaw("{$endInstant} < ?", [$nowUtc]);
        } elseif ($timeScope === 'upcoming') {
            $query->whereRaw("{$endInstant} >= ?", [$nowUtc]);
        }

        $startsAfter = UserDateTimeFormatter::parseUserDateToUtc($filters['starts_after'] ?? null, false);

        if ($startsAfter instanceof CarbonInterface) {
            $query->whereRaw("{$endInstant} >= ?", [$startsAfter]);
        }

        $startsBefore = UserDateTimeFormatter::parseUserDateToUtc($filters['starts_before'] ?? null, true);

        if ($startsBefore instanceof CarbonInterface) {
            $query->where("{$occurrencesTable}.starts_at", '<=', $startsBefore);
        }

        $startsOnLocalDate = $filters['starts_on_local_date'] ?? null;

        if (is_string($startsOnLocalDate) && $startsOnLocalDate !== '') {
            $startsOnLocalDateStart = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, false);
            $startsOnLocalDateEnd = UserDateTimeFormatter::parseUserDateToUtc($startsOnLocalDate, true);

            if ($startsOnLocalDateStart instanceof CarbonInterface && $startsOnLocalDateEnd instanceof CarbonInterface) {
                $query->whereBetween("{$occurrencesTable}.starts_at", [$startsOnLocalDateStart, $startsOnLocalDateEnd]);
            }
        }

        $hasEndTime = $this->filterQueryBuilder->normalizeBooleanFilter($filters['has_end_time'] ?? null);

        if ($hasEndTime === true) {
            $query->whereNotNull("{$occurrencesTable}.ends_at");
        } elseif ($hasEndTime === false) {
            $query->whereNull("{$occurrencesTable}.ends_at");
        }
    }

    /**
     * Filter occurrences by effective time expression (occurrence-level
     * overrides event-level) and, on pgsql, local clock-time range.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applyOccurrenceTimeExpressionFilters(QueryBuilder $query, array $filters, string $occurrencesAlias): void
    {
        $timeExpressionsTable = (new EventTimeExpression)->getTable();
        $timingMode = $this->filterQueryBuilder->normalizeTimingModeFilter($filters['timing_mode'] ?? null);
        $prayerTimes = $this->filterQueryBuilder->normalizePrayerTimeFilters($filters['prayer_time'] ?? null);

        $whereEffectiveExpressionExists = static function (QueryBuilder $outerQuery, callable $predicate) use ($timeExpressionsTable, $occurrencesAlias): void {
            $outerQuery->where(function (QueryBuilder $scope) use ($timeExpressionsTable, $occurrencesAlias, $predicate): void {
                $scope->whereExists(function (QueryBuilder $occExp) use ($timeExpressionsTable, $occurrencesAlias, $predicate): void {
                    $occExp->selectRaw('1')
                        ->from("{$timeExpressionsTable} as ete")
                        ->whereColumn('ete.event_occurrence_id', "{$occurrencesAlias}.id");
                    $predicate($occExp);
                })->orWhere(function (QueryBuilder $fallbackScope) use ($timeExpressionsTable, $occurrencesAlias, $predicate): void {
                    $fallbackScope
                        ->whereNotExists(function (QueryBuilder $hasOccExp) use ($timeExpressionsTable, $occurrencesAlias): void {
                            $hasOccExp->selectRaw('1')
                                ->from("{$timeExpressionsTable} as ete_occ")
                                ->whereColumn('ete_occ.event_occurrence_id', "{$occurrencesAlias}.id");
                        })
                        ->whereExists(function (QueryBuilder $eventExp) use ($timeExpressionsTable, $occurrencesAlias, $predicate): void {
                            $eventExp->selectRaw('1')
                                ->from("{$timeExpressionsTable} as ete")
                                ->whereColumn('ete.event_id', "{$occurrencesAlias}.event_id")
                                ->whereNull('ete.event_occurrence_id')
                                ->whereNull('ete.event_session_id');
                            $predicate($eventExp);
                        });
                });
            });
        };

        if ($timingMode === TimingMode::PrayerRelative->value) {
            $whereEffectiveExpressionExists($query, static function (QueryBuilder $ete): void {
                $ete->where('ete.time_mode', TimingMode::PrayerRelative->value);
            });
        } elseif ($timingMode === TimingMode::Absolute->value) {
            $query->where(function (QueryBuilder $scope) use ($timeExpressionsTable, $occurrencesAlias): void {
                $scope->where(function (QueryBuilder $occScope) use ($timeExpressionsTable, $occurrencesAlias): void {
                    $occScope
                        ->whereExists(function (QueryBuilder $hasOccExp) use ($timeExpressionsTable, $occurrencesAlias): void {
                            $hasOccExp->selectRaw('1')
                                ->from("{$timeExpressionsTable} as ete_occ")
                                ->whereColumn('ete_occ.event_occurrence_id', "{$occurrencesAlias}.id");
                        })
                        ->whereNotExists(function (QueryBuilder $occPrayer) use ($timeExpressionsTable, $occurrencesAlias): void {
                            $occPrayer->selectRaw('1')
                                ->from("{$timeExpressionsTable} as ete")
                                ->whereColumn('ete.event_occurrence_id', "{$occurrencesAlias}.id")
                                ->where('ete.time_mode', TimingMode::PrayerRelative->value);
                        });
                })->orWhere(function (QueryBuilder $eventScope) use ($timeExpressionsTable, $occurrencesAlias): void {
                    $eventScope
                        ->whereNotExists(function (QueryBuilder $hasOccExp) use ($timeExpressionsTable, $occurrencesAlias): void {
                            $hasOccExp->selectRaw('1')
                                ->from("{$timeExpressionsTable} as ete_occ")
                                ->whereColumn('ete_occ.event_occurrence_id', "{$occurrencesAlias}.id");
                        })
                        ->whereNotExists(function (QueryBuilder $eventPrayer) use ($timeExpressionsTable, $occurrencesAlias): void {
                            $eventPrayer->selectRaw('1')
                                ->from("{$timeExpressionsTable} as ete")
                                ->whereColumn('ete.event_id', "{$occurrencesAlias}.event_id")
                                ->whereNull('ete.event_occurrence_id')
                                ->whereNull('ete.event_session_id')
                                ->where('ete.time_mode', TimingMode::PrayerRelative->value);
                        });
                });
            });
        }

        if ($prayerTimes !== []) {
            $whereEffectiveExpressionExists($query, static function (QueryBuilder $ete) use ($prayerTimes): void {
                $ete->where(function (QueryBuilder $inner) use ($prayerTimes): void {
                    foreach ($prayerTimes as $prayerTime) {
                        $inner->orWhere(static function (QueryBuilder $matchingPrayerQuery) use ($prayerTime): void {
                            $prayerTimeEnum = EventPrayerTime::tryFrom($prayerTime);

                            if (! $prayerTimeEnum instanceof EventPrayerTime) {
                                $needle = '%'.mb_strtolower($prayerTime).'%';

                                $matchingPrayerQuery
                                    ->whereRaw("lower(coalesce(ete.display_label, '')) like ?", [$needle])
                                    ->orWhereRaw("lower(coalesce(ete.anchor_code, '')) like ?", [$needle]);

                                return;
                            }

                            $prayerReference = $prayerTimeEnum->toPrayerReference()?->value;
                            $labelNeedle = '%'.mb_strtolower($prayerTimeEnum->getLabel()).'%';

                            if ($prayerReference === null) {
                                $matchingPrayerQuery->whereRaw("lower(coalesce(ete.display_label, '')) like ?", [$labelNeedle]);

                                return;
                            }

                            $offset = $prayerTimeEnum->getDefaultOffset();
                            $relation = $offset instanceof PrayerOffset && $offset->minutes() < 0 ? 'before' : 'after';

                            $matchingPrayerQuery
                                ->where('ete.relation', $relation)
                                ->where(static function (QueryBuilder $anchorOrLabel) use ($prayerReference, $labelNeedle): void {
                                    $anchorOrLabel
                                        ->where('ete.anchor_code', $prayerReference)
                                        ->orWhereRaw("lower(coalesce(ete.display_label, '')) like ?", [$labelNeedle]);
                                });
                        });
                    }
                });
            });
        }

        $startsTimeFrom = $this->filterQueryBuilder->normalizeTimeFilter($filters['starts_time_from'] ?? null);
        $startsTimeUntil = $this->filterQueryBuilder->normalizeTimeFilter($filters['starts_time_until'] ?? null);

        if (
            $timingMode === TimingMode::Absolute->value
            && ($startsTimeFrom !== null || $startsTimeUntil !== null)
            && $this->filterQueryBuilder->databaseDriver() === 'pgsql'
        ) {
            $timezone = UserDateTimeFormatter::resolveTimezone();
            $localTimeExpression = "to_char({$occurrencesAlias}.starts_at AT TIME ZONE ?, 'HH24:MI')";

            if ($startsTimeFrom !== null && $startsTimeUntil !== null) {
                if ($startsTimeFrom <= $startsTimeUntil) {
                    $query
                        ->whereRaw("{$localTimeExpression} >= ?", [$timezone, $startsTimeFrom])
                        ->whereRaw("{$localTimeExpression} <= ?", [$timezone, $startsTimeUntil]);
                } else {
                    $query->where(static function (QueryBuilder $timeQuery) use ($localTimeExpression, $timezone, $startsTimeFrom, $startsTimeUntil): void {
                        $timeQuery
                            ->whereRaw("{$localTimeExpression} >= ?", [$timezone, $startsTimeFrom])
                            ->orWhereRaw("{$localTimeExpression} <= ?", [$timezone, $startsTimeUntil]);
                    });
                }
            } elseif ($startsTimeFrom !== null) {
                $query->whereRaw("{$localTimeExpression} >= ?", [$timezone, $startsTimeFrom]);
            } else {
                $query->whereRaw("{$localTimeExpression} <= ?", [$timezone, (string) $startsTimeUntil]);
            }
        }
    }

    /**
     * Require the parent event's current public state before projecting facts.
     */
    private function isCurrentlyPublicParentEvent(Event $event): bool
    {
        $visibility = $event->visibility;

        return $event->published_at !== null
            && in_array((string) $event->status, Event::PUBLIC_STATUSES, true)
            && ($visibility instanceof BackedEnum ? $visibility->value : (string) $visibility) === EventVisibility::Public->value;
    }

    /**
     * @param  iterable<EventTimeExpression>  $expressions
     * @return list<EventScheduleTimeFact>
     */
    private function scheduleTimeFacts(iterable $expressions): array
    {
        $facts = [];

        foreach ($expressions as $expression) {
            $facts[] = new EventScheduleTimeFact(
                timeMode: (string) $expression->time_mode,
                displayLabel: (string) $expression->display_label,
                anchorCode: (string) $expression->anchor_code,
                relation: (string) $expression->relation,
            );
        }

        return $facts;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function stringValues(array $values): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => $value instanceof BackedEnum ? (string) $value->value : (string) $value,
            $values,
        ));
    }
}
