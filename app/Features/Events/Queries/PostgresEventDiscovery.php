<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use AIArmada\Addressing\Data\AddressLocationData;
use AIArmada\Addressing\Support\AddressingTableResolver;
use AIArmada\CommerceSupport\Support\StringSimilarity;
use AIArmada\Events\Contracts\EventSearchRelationProvider;
use AIArmada\Events\Models\EventLanguage;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Persons\Enums\AssignmentStatus;
use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\EventDirectoryProjectionProvider;
use App\Features\Events\Api\EventDirectorySqlColumn;
use App\Features\Events\Api\EventDirectorySqlProjection;
use App\Features\Events\Api\EventScheduleFact;
use App\Features\Events\Api\EventScheduleFactSet;
use App\Features\Events\Api\EventScheduleSql;
use App\Features\Events\Api\EventScheduleTimeFact;
use App\Features\Events\Api\Orm\EventOrmPort;
use App\Features\Events\Api\Orm\PublicScheduleEventReads;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Events\Models\Series;
use App\Features\Events\Policies\PublicSchedulePolicy;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Persons\Models\Person;
use App\Features\Places\Api\Orm\PlaceLookup;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\PrayerOffset;
use App\Features\Prayer\Api\PrayerReference;
use App\Features\References\Api\ReferenceQueries;
use App\Platform\Models\MorphModelResolver;
use App\Platform\Search\EventDiscoveryReader;
use App\Platform\Time\UserDateTimeFormatter;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class PostgresEventDiscovery implements EventDirectoryProjectionProvider, EventDiscoveryReader, EventOrmPort, PublicScheduleEventReads
{
    public function __construct(
        private FuzzyEventMatcher $fuzzyMatcher,
        private EventDiscoveryFilterSet $filterSet,
        private PersonLookup $personLookup,
        private InstitutionLookup $institutionLookup,
        private PlaceLookup $placeLookup,

        private EventCategoryCatalog $categoryCatalog,
        private EventSearchRelationProvider $relationProvider,
        private ReferenceQueries $referenceQueries,
    ) {}

    /** @return LengthAwarePaginator<int, Event> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        $query = $this->normalizeSearchQuery($criteria->text);
        $filters = $criteria->filters;
        $perPage = $criteria->perPage;
        $sort = $criteria->sort;

        if ($query === null) {
            $queryBuilder = $this->buildDatabaseQuery(null, $filters)
                ->with($this->relationProvider->relations());

            $this->applyDatabaseOrdering($queryBuilder, $sort, null);

            return $queryBuilder->paginate($perPage);
        }

        $directQuery = $this->buildDatabaseQuery(null, $filters)
            ->with($this->relationProvider->relations());
        $this->applyDirectSearch(
            $directQuery,
            $query,
            (bool) ($filters['search_include_institutions'] ?? true),
            (bool) ($filters['search_include_persons'] ?? true),
            (bool) ($filters['search_include_references'] ?? true),
        );
        $this->applyDatabaseOrdering($directQuery, $sort, $query);

        $directMatches = $directQuery->paginate($perPage);

        if ($directMatches->total() > 0 || mb_strlen($query) < 3) {
            return $directMatches;
        }

        return $this->fuzzySearchWithDatabase($filters, $query, $perPage);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchNearbyWithDatabase(
            $criteria->latitude ?? 0.0,
            $criteria->longitude ?? 0.0,
            (int) ($criteria->radiusKm ?? 0.0),
            $criteria->filters,
            $criteria->perPage,
        );
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchNearbyWithDatabaseQuery(
            $criteria->text ?? '',
            $criteria->latitude ?? 0.0,
            $criteria->longitude ?? 0.0,
            (int) ($criteria->radiusKm ?? 0.0),
            $criteria->filters,
            $criteria->perPage,
        );
    }

    /**
     * Build the public parent-event query used by schedule-unit discovery.
     *
     * The caller can remove parent-projected time filters before hydration and
     * apply them to the effective occurrence/session timestamp instead.
     *
     * @param  array<string, mixed>  $filters
     */
    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildPublicEventQuery(?string $query, array $filters): EventBuilder
    {
        return $this->buildDatabaseQuery($this->normalizeSearchQuery($query), $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function publicScheduleFactSet(?string $query, array $filters): EventScheduleFactSet
    {
        /** @var EloquentCollection<int, Event> $events */
        $events = $this->buildPublicEventQuery($query, $filters)
            ->with($this->scheduleFactRelations())
            ->get();

        return $this->scheduleFactSet($events);
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
        /** @var EloquentCollection<int, Event> $events */
        $events = $this->homepageEventQuery($personIds, $institutionIds, $referenceIds)
            ->with($this->scheduleFactRelations())
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
        $eventsById = $this->constrainCurrentPublicEventState(
            Event::query()
                ->with($this->scheduleFactRelations())
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
        return $this->homepageFollowedEventQuery($personIds, $institutionIds, $referenceIds)
            ->pluck('events.id')
            ->map(static fn (mixed $eventId): string => (string) $eventId)
            ->values()
            ->all();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function scheduleCardRelations(): array
    {
        return collect($this->scheduleRelations())
            // Keys are strings for constrained relations, but bare nested paths
            // arrive with numeric keys — match those by value, or Eloquent
            // resurrects the dropped parents unconstrained to satisfy them.
            ->reject(function (mixed $constraints, int|string $key): bool {
                $path = is_string($key) ? $key : (is_string($constraints) ? $constraints : null);

                return is_string($path)
                    && ($path === 'primaryOccurrence'
                        || str_starts_with($path, 'primaryOccurrence.')
                        || in_array($path, [
                            'goingWindowOccurrences',
                            'primaryLocation.venue',
                            'occurrences.locations.venue',
                            'occurrences.sessions.locations.venue',
                        ], true));
            })
            ->all();
    }

    /**
     * @param  list<string>  $eventIds
     * @param  array<string, float>  $distanceByEventId
     * @return EloquentCollection<int, Event>
     */
    public function hydrateScheduleEvents(
        array $eventIds,
        bool $withOccurrenceReferences = false,
        array $distanceByEventId = [],
    ): EloquentCollection {
        if ($eventIds === []) {
            return new EloquentCollection;
        }

        $relations = $this->scheduleCardRelations();

        if ($withOccurrenceReferences) {
            $relations[] = 'occurrences.references';
        }

        /** @var EloquentCollection<string, Event> $events */
        $events = $this->hydrateEventsForIds($eventIds, $relations);

        $orderedEvents = collect($eventIds)
            ->map(static fn (string $eventId): ?Event => $events->get($eventId))
            ->filter()
            ->values();

        foreach ($orderedEvents as $event) {
            $eventId = (string) $event->getKey();

            if (array_key_exists($eventId, $distanceByEventId)) {
                $event->setAttribute('distance_km', $distanceByEventId[$eventId]);
            }
        }

        return new EloquentCollection($orderedEvents->all());
    }

    /**
     * @param  array<int|string, mixed>  $relations
     */
    public function institutionScheduleQuery(string $institutionId, array $relations): EventBuilder
    {
        return Event::query()
            ->where('institution_id', $institutionId)
            ->active()
            ->with([
                ...$relations,
                'persons.media',
                'persons.titleAssignments.title.category',
                'keyPeople.person',
                'references' => function (BelongsToMany $references): void {
                    app(PublicReferenceConstraint::class)->apply($references->getQuery());
                },
                'media',
                'institution.media',
                'classifications.term',
                'primaryOccurrence',
                'displayOccurrence.media',
                'timeExpressions',
                'links',
            ]);
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     */
    private function homepageEventQuery(array $personIds, array $institutionIds, array $referenceIds): EventBuilder
    {
        $occurrenceTable = (new EventOccurrence)->getTable();

        $query = $this->buildPublicEventQuery(null, ['time_scope' => 'all']);

        return $query
            ->where('events.status', 'approved')
            ->where(function (Builder $query) use ($personIds, $institutionIds, $referenceIds, $occurrenceTable): void {
                $query->whereHas(
                    'occurrences',
                    fn (Builder $occurrenceQuery): Builder => $occurrenceQuery
                        ->where("{$occurrenceTable}.is_featured", true)
                        ->whereIn("{$occurrenceTable}.status", Event::PUBLIC_SCHEDULE_STATUSES)
                        ->whereIn("{$occurrenceTable}.visibility", Event::PUBLIC_SCHEDULE_VISIBILITIES),
                );

                $this->applyHomepageFollowConditions($query, $personIds, $institutionIds, $referenceIds);
            });
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     */
    private function homepageFollowedEventQuery(array $personIds, array $institutionIds, array $referenceIds): EventBuilder
    {
        $baseQuery = $this->buildPublicEventQuery(null, ['time_scope' => 'all']);
        $query = $baseQuery->whereIn('events.status', Event::PUBLIC_STATUSES);

        $query->where(function (Builder $query) use ($personIds, $institutionIds, $referenceIds): void {
            $this->applyHomepageFollowConditions($query, $personIds, $institutionIds, $referenceIds);
        });

        return $query;
    }

    /**
     * @param  Builder<Event>  $query
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     */
    private function applyHomepageFollowConditions(Builder $query, array $personIds, array $institutionIds, array $referenceIds): void
    {
        if ($personIds !== []) {
            $query->orWhereHas('persons', fn (Builder $query): Builder => $query->whereIn('persons.id', $personIds));
        }

        if ($institutionIds !== []) {
            $query->orWhereIn('events.institution_id', $institutionIds);
        }

        if ($referenceIds !== []) {
            $query->orWhereHas('references', function (Builder $query) use ($referenceIds): void {
                app(PublicReferenceConstraint::class)->apply($query)->whereIn('references.id', $referenceIds);
            });
        }
    }

    /**
     * Project owner-scoped event models into immutable schedule facts.
     *
     * @param  EloquentCollection<int, Event>  $events
     * @param  array<string, float>  $distanceByEventId
     */
    private function scheduleFactSet(EloquentCollection $events, array $distanceByEventId = []): EventScheduleFactSet
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
     * Reapply current public parent and occurrence state to an id rehydration
     * query. Search engines filter against indexed values, which may be stale.
     */
    private function constrainCurrentPublicEventState(EventBuilder $query): EventBuilder
    {
        return $query
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereHas('occurrences', function (Builder $occurrences): void {
                $occurrences
                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
            });
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

    /** @return array<int|string, string|\Closure> */
    private function scheduleFactRelations(): array
    {
        $publicScheduleScope = $this->constrainPublicSchedule(...);

        return [
            'timeExpressions',
            'occurrences' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.timeExpressions',
            'occurrences.sessions' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('sort_order')
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.sessions.timeExpressions',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleRelations(): array
    {
        $publicScheduleScope = $this->constrainPublicSchedule(...);

        return [
            ...$this->relationProvider->relations(),
            'primaryLocation.venue',
            'occurrences' => function (Relation $query) use ($publicScheduleScope): void {
                $publicScheduleScope($query);
                $query
                    ->orderBy('starts_at')
                    ->orderBy('created_at')
                    ->orderBy('id');
            },
            'occurrences.media',
            'occurrences.timeExpressions',
            'occurrences.locations' => static function (Relation $query): void {
                $query->whereNull('event_session_id')->where('status', 'active')->where('visibility', 'public');
            },
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
            'occurrences.sessions.involvements.involveable' => function (MorphTo $relation): void {
                // Cards render formatted_name per involveable; preload the full
                // title graph so the accessor never falls back to per-person queries.
                $relation->morphWith([
                    Person::class => [
                        'titleAssignments' => fn (Relation $query) => $query
                            ->where('status', AssignmentStatus::Active)
                            ->with('title.category'),
                    ],
                ]);
            },
        ];
    }

    /**
     * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
     * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
     * @template TResult
     *
     * @param  Relation<TRelatedModel, TDeclaringModel, TResult>  $query
     */
    private function constrainPublicSchedule(Relation $query): void
    {
        $query
            ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
            ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function buildDatabaseQuery(?string $query, array $filters): EventBuilder
    {
        $timeScope = $this->normalizeTimeScope($filters['time_scope'] ?? null);

        $queryBuilder = Event::query();
        $table = $queryBuilder->getModel()->getTable();
        $startsAtColumn = PrimaryOccurrenceSql::column('starts_at', $table);
        $endsAtColumn = PrimaryOccurrenceSql::column('ends_at', $table);
        $endInstantColumn = "coalesce({$endsAtColumn}, {$startsAtColumn})";

        $queryBuilder
            ->whereIn("{$table}.status", Event::PUBLIC_STATUSES)
            ->where("{$table}.visibility", EventVisibility::Public->value)
            ->whereNotNull("{$table}.published_at")
            ->whereHas('occurrences');

        $startsAfter = $this->startsAfterDateTime($filters, $timeScope);

        if ($startsAfter instanceof CarbonInterface) {
            $queryBuilder->whereRaw($endInstantColumn.' >= ?', [$startsAfter]);
        }

        $startsBefore = $this->startsBeforeDateTime($filters);

        if ($startsBefore instanceof CarbonInterface) {
            $queryBuilder->whereRaw($startsAtColumn.' <= ?', [$startsBefore]);
        } elseif ($timeScope === 'past') {
            $queryBuilder->whereRaw($endInstantColumn.' < ?', [now()]);
        }

        $startsOnLocalDateRange = $this->startsOnLocalDateRange($filters);

        if ($startsOnLocalDateRange !== null) {
            [$startsOnLocalDateStart, $startsOnLocalDateEnd] = $startsOnLocalDateRange;

            $queryBuilder->whereRaw($startsAtColumn.' between ? and ?', [$startsOnLocalDateStart, $startsOnLocalDateEnd]);
        }

        if (filled($query)) {
            $this->applyDirectSearch(
                $queryBuilder,
                $query,
                (bool) ($filters['search_include_institutions'] ?? true),
                (bool) ($filters['search_include_persons'] ?? true),
                (bool) ($filters['search_include_references'] ?? true),
            );
        }

        $this->applyLocationAddressFilter($queryBuilder, $this->filterSet->location($filters));

        $languageCodes = $this->normalizeArrayFilter($filters['language_codes'] ?? null);

        if ($languageCodes !== []) {
            $eventTable = $queryBuilder->getModel()->getTable();
            $languageTable = (new EventLanguage)->getTable();

            $queryBuilder->whereExists(function ($languageQuery) use ($eventTable, $languageCodes, $languageTable): void {
                $languageQuery
                    ->selectRaw('1')
                    ->from("{$languageTable} as event_language_filters")
                    ->whereColumn('event_language_filters.event_id', "{$eventTable}.id")
                    ->whereIn('event_language_filters.language_code', $languageCodes);
            });
        }

        $categoryIds = $this->categoryCatalog->descendantIds($this->normalizeArrayFilter($filters['event_category_ids'] ?? null));

        if ($categoryIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($categoryIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $categoryIds)
                    ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE);
            });
        }

        $eventFormats = $this->normalizeArrayFilter($filters['event_format'] ?? null);

        if ($eventFormats !== []) {
            $queryBuilder->whereIn('delivery_mode', $eventFormats);
        }

        if (! empty($filters['gender'])) {
            $queryBuilder->whereHas('audiences', function (Builder $genderQuery) use ($filters): void {
                $genderQuery
                    ->where('audience_type', 'gender')
                    ->where('value', $filters['gender']);
            });
        }

        $ageGroups = $this->normalizeArrayFilter($filters['age_group'] ?? null);

        if ($ageGroups !== []) {
            $queryBuilder->whereHas('audiences', function (Builder $ageGroupQuery) use ($ageGroups): void {
                $ageGroupQuery
                    ->where('audience_type', 'age_group')
                    ->whereIn('value', $ageGroups);
            });
        }

        $childrenAllowed = $this->normalizeBooleanFilter($filters['children_allowed'] ?? null);

        if ($childrenAllowed !== null) {
            $queryBuilder->whereHas('audienceProfiles', fn (Builder $q) => $q->where('is_child_friendly', $childrenAllowed));
        }

        $isMuslimOnly = $this->normalizeBooleanFilter($filters['is_muslim_only'] ?? null);

        if ($isMuslimOnly !== null) {
            if ($isMuslimOnly) {
                $queryBuilder->whereHas('audiences', fn (Builder $q) => $q->where('audience_type', 'religion')->where('value', 'muslim_only'));
            } else {
                $queryBuilder->whereDoesntHave('audiences', fn (Builder $q) => $q->where('audience_type', 'religion'));
            }
        }

        if (! empty($filters['institution_id'])) {
            $queryBuilder->where('institution_id', $filters['institution_id']);
        }

        $savedEventIds = $this->uuidFilterValues($filters['saved_event_ids'] ?? null);

        if ($savedEventIds !== []) {
            $queryBuilder->whereIn('events.id', $savedEventIds);
        }

        $goingEventIds = $this->uuidFilterValues($filters['going_event_ids'] ?? null);

        if ($goingEventIds !== []) {
            $queryBuilder->whereIn('events.id', $goingEventIds);
        }

        if (! empty($filters['venue_id'])) {
            $venueId = $filters['venue_id'];

            $queryBuilder->where(function (Builder $venueFilterQuery) use ($venueId): void {
                // An explicit default marker always wins: the package
                // branch only applies when no default is set, mirroring
                // the resolver and the geographic address filter. A
                // missing (orphan) default marker is still explicit, so
                // it suppresses the package branch too.
                $venueFilterQuery
                    ->where('default_venue_id', $venueId)
                    ->orWhere(function (Builder $packageVenueQuery) use ($venueId): void {
                        $packageVenueQuery
                            ->whereNull('events.default_venue_id')
                            ->whereHas('locations', function (Builder $locationQuery) use ($venueId): void {
                                PrimaryLocationSql::constrainToSelected($locationQuery->getQuery());
                                $locationQuery->where('venue_id', $venueId);
                            });
                    });
            });
        }

        $personIds = $this->uuidFilterValues($filters['person_ids'] ?? null);

        if ($personIds !== []) {
            $queryBuilder->whereHas('persons', function (Builder $personQuery) use ($personIds) {
                $personQuery->whereIn('persons.id', $personIds);
            });
        }

        $keyPersonRoles = $this->normalizeKeyPersonRoles($filters['key_person_roles'] ?? null);

        if ($keyPersonRoles !== []) {
            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($keyPersonRoles): void {
                $keyPersonQuery->whereIn('role_code', $keyPersonRoles);
            });
        }

        foreach ([
            'person_in_charge_ids' => EventKeyPersonRole::PersonInCharge,
            'moderator_ids' => EventKeyPersonRole::Moderator,
            'imam_ids' => EventKeyPersonRole::Imam,
            'khatib_ids' => EventKeyPersonRole::Khatib,
            'bilal_ids' => EventKeyPersonRole::Bilal,
        ] as $filterKey => $role) {
            $roleSpecificIds = $this->uuidFilterValues($filters[$filterKey] ?? null);

            if ($roleSpecificIds === []) {
                continue;
            }

            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($roleSpecificIds, $role): void {
                $keyPersonQuery
                    ->where('role_code', $role->value)
                    ->where('involveable_type', 'person')
                    ->whereIn('involveable_id', $roleSpecificIds);
            });
        }

        $personInChargeSearch = $this->normalizeTextFilter($filters['person_in_charge_search'] ?? null);

        if ($personInChargeSearch !== null) {
            $queryBuilder->whereHas('keyPeople', function (Builder $keyPersonQuery) use ($personInChargeSearch): void {
                $keyPersonQuery
                    ->where('role_code', EventKeyPersonRole::PersonInCharge->value)
                    ->where(function (Builder $personInChargeQuery) use ($personInChargeSearch): void {
                        $personInChargeQuery
                            ->whereLike('event_involvements.display_name', "%{$personInChargeSearch}%")
                            ->orWhereHas('person', function (Builder $personQuery) use ($personInChargeSearch): void {
                                $personQuery
                                    ->whereLike('persons.name', "%{$personInChargeSearch}%")
                                    ->orWhereLike('persons.searchable_name', "%{$personInChargeSearch}%");
                            });
                    });
            });
        }

        $personNameSearch = $this->normalizeTextFilter($filters['person_name_search'] ?? null);

        if ($personNameSearch !== null) {
            $queryBuilder->where(function (Builder $personNameQuery) use ($personNameSearch): void {
                $personNameQuery
                    ->whereHas('persons', function (Builder $speakerQuery) use ($personNameSearch): void {
                        $speakerQuery
                            ->whereLike('persons.name', "%{$personNameSearch}%")
                            ->orWhereLike('persons.searchable_name', "%{$personNameSearch}%");
                    })
                    ->orWhereHas('keyPeople', function (Builder $keyPersonQuery) use ($personNameSearch): void {
                        $keyPersonQuery
                            ->whereLike('event_involvements.display_name', "%{$personNameSearch}%")
                            ->orWhereHas('person', function (Builder $personQuery) use ($personNameSearch): void {
                                $personQuery
                                    ->whereLike('persons.name', "%{$personNameSearch}%")
                                    ->orWhereLike('persons.searchable_name', "%{$personNameSearch}%");
                            });
                    });
            });
        }

        $disciplineTagIds = $this->normalizeArrayFilter($filters['discipline_tag_ids'] ?? null);

        if ($disciplineTagIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($disciplineTagIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $disciplineTagIds)
                    ->where('taxonomy_code', 'discipline');
            });
        }

        $domainTagIds = $this->normalizeArrayFilter($filters['domain_tag_ids'] ?? null);

        if ($domainTagIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($domainTagIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $domainTagIds)
                    ->where('taxonomy_code', 'domain');
            });
        }

        $sourceTagIds = $this->normalizeArrayFilter($filters['source_tag_ids'] ?? null);

        if ($sourceTagIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($sourceTagIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $sourceTagIds)
                    ->where('taxonomy_code', 'source');
            });
        }

        $issueTagIds = $this->normalizeArrayFilter($filters['issue_tag_ids'] ?? null);

        if ($issueTagIds !== []) {
            $queryBuilder->whereHas('classifications', function (Builder $classificationQuery) use ($issueTagIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $issueTagIds)
                    ->where('taxonomy_code', 'issue');
            });
        }

        $referenceFilter = $this->resolvedReferenceFilter($filters);

        if ($referenceFilter['ids'] !== []) {
            $queryBuilder->whereHas('references', function (Builder $referenceQuery) use ($referenceFilter): void {
                app(PublicReferenceConstraint::class)->apply($referenceQuery)
                    ->whereIn('references.id', $referenceFilter['ids']);
            });
        } elseif ($referenceFilter['has_author_filter'] || $referenceFilter['has_id_filter']) {
            $queryBuilder->whereRaw('1 = 0');
        }

        $timingMode = $this->normalizeTimingModeFilter($filters['timing_mode'] ?? null);
        $prayerTimes = $this->normalizePrayerTimeFilters($filters['prayer_time'] ?? null);

        if ($prayerTimes !== [] && $timingMode !== TimingMode::Absolute->value) {
            $queryBuilder
                ->whereHas('timeExpressions', function (Builder $prayerQuery) use ($prayerTimes): void {
                    $prayerQuery->where('time_mode', TimingMode::PrayerRelative->value);
                    $prayerQuery->where('anchor_type', 'prayer');

                    $prayerQuery->where(function (Builder $inner) use ($prayerTimes): void {
                        foreach ($prayerTimes as $prayerTime) {
                            $inner->orWhere(function (Builder $matchingPrayerQuery) use ($prayerTime): void {
                                $prayerTimeEnum = EventPrayerTime::tryFrom($prayerTime);

                                if ($prayerTimeEnum instanceof EventPrayerTime) {
                                    $prayerReference = $prayerTimeEnum->toPrayerReference();
                                    $prayerLabel = mb_strtolower($prayerTimeEnum->getLabel());

                                    if ($prayerReference instanceof PrayerReference) {
                                        $offset = $prayerTimeEnum->getDefaultOffset();
                                        $relation = $offset instanceof PrayerOffset && $offset->minutes() < 0 ? 'before' : 'after';

                                        $matchingPrayerQuery
                                            ->where('relation', $relation)
                                            ->where(function (Builder $prayerMatch) use ($prayerLabel, $prayerReference): void {
                                                $prayerMatch
                                                    ->where('anchor_code', $prayerReference->value)
                                                    ->orWhereLike('display_label', "%{$prayerLabel}%");
                                            });

                                        return;
                                    }

                                    $matchingPrayerQuery->whereLike('display_label', "%{$prayerLabel}%");

                                    return;
                                }

                                $matchingPrayerQuery->whereLike('display_label', "%{$prayerTime}%");

                                if (($prayerReference = $this->resolvePrayerReferenceFromFilter($prayerTime)) instanceof PrayerReference) {
                                    $matchingPrayerQuery->orWhere('anchor_code', $prayerReference->value);
                                }
                            });
                        }
                    });
                });
        }

        if ($timingMode !== null) {
            if ($timingMode === TimingMode::PrayerRelative->value) {
                $queryBuilder->whereHas('timeExpressions', fn (Builder $timeQuery) => $timeQuery->where('time_mode', TimingMode::PrayerRelative->value));
            } else {
                $queryBuilder->whereDoesntHave('timeExpressions', fn (Builder $timeQuery) => $timeQuery->where('time_mode', TimingMode::PrayerRelative->value));
            }
        }

        $startsTimeFrom = $this->normalizeTimeFilter($filters['starts_time_from'] ?? null);
        $startsTimeUntil = $this->normalizeTimeFilter($filters['starts_time_until'] ?? null);

        if (
            $timingMode === TimingMode::Absolute->value
            && ($startsTimeFrom !== null || $startsTimeUntil !== null)
        ) {
            $this->applyAbsoluteTimeRangeFilter($queryBuilder, $startsTimeFrom, $startsTimeUntil);
        }

        $hasEventUrl = $this->normalizeBooleanFilter($filters['has_event_url'] ?? null);

        if ($hasEventUrl === true) {
            $queryBuilder->whereHas('links', fn (Builder $q) => $q->where('link_type', 'external')->where('url', '!=', ''));
        } elseif ($hasEventUrl === false) {
            $queryBuilder->whereDoesntHave('links', fn (Builder $q) => $q->where('link_type', 'external')->where('url', '!=', ''));
        }

        $hasLiveUrl = $this->normalizeBooleanFilter($filters['has_live_url'] ?? null);

        if ($hasLiveUrl === true) {
            $queryBuilder->whereHas('links', fn (Builder $q) => $q->where('link_type', 'streaming')->where('url', '!=', ''));
        } elseif ($hasLiveUrl === false) {
            $queryBuilder->whereDoesntHave('links', fn (Builder $q) => $q->where('link_type', 'streaming')->where('url', '!=', ''));
        }

        $hasEndTime = $this->normalizeBooleanFilter($filters['has_end_time'] ?? null);

        if ($hasEndTime === true) {
            $queryBuilder->whereRaw($endsAtColumn.' is not null');
        } elseif ($hasEndTime === false) {
            $queryBuilder->whereRaw($endsAtColumn.' is null');
        }

        return $queryBuilder;
    }

    protected function applyDirectSearch(
        EventBuilder $queryBuilder,
        string $search,
        bool $includeInstitutions = true,
        bool $includePersons = true,
        bool $includeReferences = true,
    ): void {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return;
        }

        $collapsedSearch = preg_replace('/\s+/u', ' ', $normalizedSearch) ?? '';
        $collapsedWildcardSearch = '%'.str_replace(' ', '%', $collapsedSearch).'%';

        /** @var list<string> $searchTokens */
        $searchTokens = array_values(array_filter(
            explode(' ', $collapsedSearch),
            static fn (string $token): bool => $token !== ''
        ));

        $personIds = $includePersons ? $this->personLookup->publicSearchIds($normalizedSearch) : [];
        $institutionIds = $includeInstitutions ? $this->institutionLookup->publicSearchIds($normalizedSearch) : [];
        $referenceIds = $includeReferences ? $this->referenceQueries->publicSearchIds($normalizedSearch) : [];

        $queryBuilder->where(function (Builder $nestedQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens, $personIds, $institutionIds, $referenceIds, $includePersons): void {
            $nestedQuery->where(function (Builder $titleQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
                $titleQuery
                    ->whereLike('title', "%{$normalizedSearch}%")
                    ->orWhereLike('title', $collapsedWildcardSearch);

                foreach ($searchTokens as $token) {
                    if (mb_strlen($token) < 3) {
                        continue;
                    }

                    $titleQuery->orWhereLike('title', "%{$token}%");
                }
            });

            $nestedQuery->orWhereHas('occurrences', function (Builder $occurrenceQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
                $occurrenceQuery
                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES)
                    ->where(function (Builder $scheduleQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
                        $scheduleQuery->where(function (Builder $titleQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
                            $titleQuery
                                ->whereLike('title', "%{$normalizedSearch}%")
                                ->orWhereLike('title', $collapsedWildcardSearch);

                            foreach ($searchTokens as $token) {
                                if (mb_strlen($token) < 3) {
                                    continue;
                                }

                                $titleQuery->orWhereLike('title', "%{$token}%");
                            }
                        })
                            ->orWhereHas('sessions', function (Builder $sessionQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
                                $sessionQuery
                                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES)
                                    ->where(function (Builder $titleQuery) use ($normalizedSearch, $collapsedWildcardSearch, $searchTokens): void {
                                        $titleQuery
                                            ->whereLike('title', "%{$normalizedSearch}%")
                                            ->orWhereLike('title', $collapsedWildcardSearch);

                                        foreach ($searchTokens as $token) {
                                            if (mb_strlen($token) < 3) {
                                                continue;
                                            }

                                            $titleQuery->orWhereLike('title', "%{$token}%");
                                        }
                                    });
                            });
                    });
            });

            if ($institutionIds !== []) {
                $institutionIdExpression = 'events.institution_id';

                $nestedQuery->orWhere(function (Builder $institutionQuery) use ($institutionIdExpression, $institutionIds): void {
                    foreach ($institutionIds as $index => $institutionId) {
                        if ($index === 0) {
                            $institutionQuery->whereRaw("{$institutionIdExpression} = ?", [$institutionId]);

                            continue;
                        }

                        $institutionQuery->orWhereRaw("{$institutionIdExpression} = ?", [$institutionId]);
                    }
                });
            }

            if ($includePersons) {
                $nestedQuery->orWhereHas('keyPeople', function (Builder $keyPeopleQuery) use ($personIds, $normalizedSearch): void {
                    $keyPeopleQuery->where(function (Builder $inner) use ($personIds, $normalizedSearch): void {
                        $inner
                            ->whereLike('event_involvements.display_name', "%{$normalizedSearch}%")
                            ->orWhereHas('person', fn (Builder $personQuery) => $personQuery
                                ->whereLike('name', "%{$normalizedSearch}%")
                                ->orWhereLike('searchable_name', "%{$normalizedSearch}%")
                            );

                        if ($personIds !== []) {
                            $inner->orWhereIn('event_involvements.involveable_id', $personIds);
                        }
                    });
                });
            }

            if ($referenceIds !== []) {
                $nestedQuery->orWhereHas('references', function (Builder $referenceQuery) use ($referenceIds): void {
                    app(PublicReferenceConstraint::class)->apply($referenceQuery)
                        ->whereIn('references.id', $referenceIds);
                });
            }
        });
    }

    /**
     * @param  Builder<Event>  $queryBuilder
     */
    protected function applyDatabaseOrdering(Builder $queryBuilder, string $sort, ?string $query): void
    {
        $startsAtColumn = PrimaryOccurrenceSql::column('starts_at', $queryBuilder->getModel()->getTable());

        if ($sort === 'relevance' && is_string($query) && $query !== '') {
            $operator = $this->databaseLikeOperator();

            $queryBuilder
                ->orderByRaw(
                    "CASE
                        WHEN events.title {$operator} ? THEN 1
                        ELSE 2
                    END",
                    ["%{$query}%"]
                )
                ->orderByRaw("{$startsAtColumn} asc");

            return;
        }

        if ($sort === 'popular') {
            $queryBuilder
                ->withCount('savedBy')
                ->orderByDesc('saved_by_count')
                ->orderByRaw("{$startsAtColumn} asc");

            return;
        }

        $queryBuilder->orderByRaw("{$startsAtColumn} asc");
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function fuzzySearchWithDatabase(array $filters, string $search, int $perPage): LengthAwarePaginator
    {
        $normalizedSearch = StringSimilarity::normalize($search);

        if ($normalizedSearch === '') {
            $queryBuilder = $this->buildDatabaseQuery(null, $filters)
                ->with($this->relationProvider->relations())
                ->orderByRaw(PrimaryOccurrenceSql::column('starts_at', (new Event)->getTable()).' asc');

            return $queryBuilder->paginate($perPage);
        }

        $candidateQuery = $this->buildDatabaseQuery(null, $filters)
            ->select(['events.id', 'events.title'])
            ->tap(fn (Builder $query): Builder => $this->fuzzyMatcher->applyFuzzyTitleCandidateFilter($query, $normalizedSearch))
            ->tap(fn (Builder $query): Builder => $this->fuzzyMatcher->applyFuzzyTitleCandidateOrdering($query, $normalizedSearch));

        $rankedCandidates = $candidateQuery
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->id,
                'score' => $this->fuzzyMatcher->eventSimilarityScore($normalizedSearch, $event),
            ])
            ->filter(static fn (array $candidate): bool => $candidate['score'] >= 0.70)
            ->sortByDesc('score')
            ->values();

        $currentPage = max(1, (int) Paginator::resolveCurrentPage());
        $paginationMeta = [
            'path' => request()->url(),
            'query' => request()->query(),
        ];

        if ($rankedCandidates->isEmpty()) {
            return new Paginator(collect(), 0, $perPage, $currentPage, $paginationMeta);
        }

        /** @var list<string> $orderedIds */
        $orderedIds = $rankedCandidates->pluck('id')->all();
        $paginatedIds = array_slice($orderedIds, ($currentPage - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new Paginator(collect(), count($orderedIds), $perPage, $currentPage, $paginationMeta);
        }

        $events = $this->buildDatabaseQuery(null, $filters)
            ->with($this->relationProvider->relations())
            ->whereIn('events.id', $paginatedIds)
            ->get()
            ->sortBy(static function (Event $event) use ($paginatedIds): int {
                $position = array_search($event->id, $paginatedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values();

        return new Paginator($events, count($orderedIds), $perPage, $currentPage, $paginationMeta);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchNearbyWithDatabase(
        float $lat,
        float $lng,
        int $radiusKm,
        array $filters,
        int $perPage
    ): LengthAwarePaginator {
        return $this->paginateNearby($this->buildDatabaseQuery(null, $filters), $lat, $lng, $radiusKm, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    protected function searchNearbyWithDatabaseQuery(
        string $query,
        float $lat,
        float $lng,
        int $radiusKm,
        array $filters,
        int $perPage
    ): LengthAwarePaginator {
        $queryBuilder = $this->buildDatabaseQuery(null, $filters);
        $this->applyDirectSearch($queryBuilder, $query,
            (bool) ($filters['search_include_institutions'] ?? true),
            (bool) ($filters['search_include_persons'] ?? true),
            (bool) ($filters['search_include_references'] ?? true),
        );

        return $this->paginateNearby($queryBuilder, $lat, $lng, $radiusKm, $perPage);
    }

    /**
     * Join the effective venue (default, otherwise the canonically ordered
     * primary package location venue) plus the institution place, then
     * measure distance from the selected address entirely in SQL.
     *
     * @return LengthAwarePaginator<int, Event>
     */
    private function paginateNearby(EventBuilder $queryBuilder, float $lat, float $lng, int $radiusKm, int $perPage): LengthAwarePaginator
    {
        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $addressesTable = AddressingTableResolver::resolve('addresses');
        $eventsTable = (new Event)->getTable();
        $institutionIdExpression = "{$eventsTable}.institution_id";

        $primaryPackageVenue = PrimaryLocationSql::venueId($eventsTable);
        $effectiveVenue = "coalesce({$eventsTable}.default_venue_id, {$primaryPackageVenue})";
        $venueTable = $this->placeLookup->venueTable();
        $venueExists = "exists (select 1 from {$venueTable} where {$venueTable}.id = ({$effectiveVenue}))";
        $latitudeExpression = "case when ({$effectiveVenue}) is null then institution_addresses.latitude when {$venueExists} then venue_addresses.latitude end";
        $longitudeExpression = "case when ({$effectiveVenue}) is null then institution_addresses.longitude when {$venueExists} then venue_addresses.longitude end";
        $distanceSql = "(6371 * acos(cos(radians(?)) * cos(radians({$latitudeExpression})) * cos(radians({$longitudeExpression}) - radians(?)) + sin(radians(?)) * sin(radians({$latitudeExpression}))))";
        $venueMorphType = $this->placeLookup->venueMorphType();
        $institutionMorphType = $this->institutionLookup->institutionMorphType();

        return $queryBuilder
            ->leftJoin("{$addressablesTable} as venue_addressables", function ($join) use ($venueMorphType, $effectiveVenue) {
                $join->whereRaw("venue_addressables.addressable_id = {$effectiveVenue}")
                    ->where('venue_addressables.addressable_type', $venueMorphType)
                    ->where('venue_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as venue_addresses", 'venue_addresses.id', '=', 'venue_addressables.address_id')
            ->leftJoin("{$addressablesTable} as institution_addressables", function ($join) use ($institutionIdExpression, $institutionMorphType) {
                $join->whereRaw("institution_addressables.addressable_id = {$institutionIdExpression}")
                    ->where('institution_addressables.addressable_type', $institutionMorphType)
                    ->where('institution_addressables.is_primary', true);
            })
            ->leftJoin("{$addressesTable} as institution_addresses", 'institution_addresses.id', '=', 'institution_addressables.address_id')
            ->whereRaw("{$latitudeExpression} is not null")
            ->whereRaw("{$longitudeExpression} is not null")
            ->select("{$eventsTable}.*")
            ->selectRaw("{$distanceSql} as distance_km", [$lat, $lng, $lat])
            ->whereRaw("{$distanceSql} <= ?", [$lat, $lng, $lat, $radiusKm])
            ->with($this->relationProvider->relations())
            ->orderBy('distance_km', 'asc')
            ->orderByRaw(PrimaryOccurrenceSql::column('starts_at', (new Event)->getTable()).' asc')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsAfterDateTime(array $filters, string $timeScope = 'upcoming'): ?CarbonInterface
    {
        $startsAfter = $this->parseDateFilter($filters['starts_after'] ?? null, false);

        if ($startsAfter instanceof CarbonInterface) {
            if ($timeScope === 'upcoming' && now()->greaterThan($startsAfter)) {
                return now();
            }

            return $startsAfter;
        }

        if ($timeScope === 'upcoming') {
            return now();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsBeforeDateTime(array $filters): ?CarbonInterface
    {
        return $this->parseDateFilter($filters['starts_before'] ?? null, true);
    }

    protected function applyLocationAddressFilter(EventBuilder $queryBuilder, AddressLocationData $location): void
    {
        $criteria = $location->criteria();
        $assignments = $location->assignments();

        if ($criteria === [] && $assignments === []) {
            return;
        }

        $addressesTable = AddressingTableResolver::resolve('addresses');
        $addressablesTable = AddressingTableResolver::resolve('addressables');
        $assignmentsTable = AddressingTableResolver::resolve('address_area_assignments');
        $venueTable = $this->placeLookup->venueTable();
        $venueMorphType = $this->placeLookup->venueMorphType();
        $institutionMorphType = $this->institutionLookup->institutionMorphType();

        $matchesOwnerAddress = static function (Builder $ownerQuery, string $ownerColumn, string $morphType) use ($addressesTable, $addressablesTable, $assignmentsTable, $criteria, $assignments): void {
            $ownerQuery->whereExists(function ($addressQuery) use ($addressesTable, $addressablesTable, $assignmentsTable, $ownerColumn, $morphType, $criteria, $assignments): void {
                $addressQuery
                    ->selectRaw('1')
                    ->from($addressesTable)
                    ->join($addressablesTable, "{$addressesTable}.id", '=', "{$addressablesTable}.address_id")
                    ->whereRaw("{$addressablesTable}.addressable_id = {$ownerColumn}")
                    ->where("{$addressablesTable}.addressable_type", $morphType)
                    ->where("{$addressablesTable}.is_primary", true);

                foreach ($criteria as $column => $value) {
                    $addressQuery->where("{$addressesTable}.{$column}", $value);
                }

                foreach ($assignments as $role => $areaId) {
                    $addressQuery->whereExists(function ($assignmentQuery) use ($assignmentsTable, $addressesTable, $role, $areaId): void {
                        $assignmentQuery
                            ->selectRaw('1')
                            ->from($assignmentsTable)
                            ->whereColumn("{$assignmentsTable}.address_id", "{$addressesTable}.id")
                            ->where("{$assignmentsTable}.role", $role)
                            ->where("{$assignmentsTable}.address_area_id", $areaId)
                            ->where("{$assignmentsTable}.is_primary", true);
                    });
                }
            });
        };

        $matchesEventVenueAddress = static function (Builder $eventQuery) use ($matchesOwnerAddress, $venueTable, $venueMorphType): void {
            // The primary package venue only places the event when no
            // default venue wins; a set default marker suppresses this
            // branch even when the default venue itself does not match.
            // Only the selected canonical primary row can match: a leading
            // no-venue row means the event is placed by its institution.
            $eventQuery
                ->whereNull('events.default_venue_id')
                ->whereHas('locations', function (Builder $locationQuery) use ($matchesOwnerAddress, $venueTable, $venueMorphType): void {
                    PrimaryLocationSql::constrainToSelected($locationQuery->getQuery());
                    $locationQuery->whereHas('venue', function (Builder $venueQuery) use ($matchesOwnerAddress, $venueTable, $venueMorphType): void {
                        $matchesOwnerAddress($venueQuery, "{$venueTable}.id", $venueMorphType);
                    });
                });
        };

        $queryBuilder->where(function (Builder $locationQuery) use ($matchesEventVenueAddress, $matchesOwnerAddress, $institutionMorphType, $venueMorphType): void {
            $locationQuery
                ->where(function (Builder $venueQuery) use ($matchesEventVenueAddress, $matchesOwnerAddress, $venueMorphType): void {
                    $venueQuery
                        ->where(function (Builder $venueLocationQuery) use ($matchesEventVenueAddress, $matchesOwnerAddress, $venueMorphType): void {
                            $venueLocationQuery
                                ->where(function (Builder $defaultVenueQuery) use ($matchesOwnerAddress, $venueMorphType): void {
                                    $defaultVenueQuery->whereNotNull('events.default_venue_id');
                                    $matchesOwnerAddress($defaultVenueQuery, 'events.default_venue_id', $venueMorphType);
                                })
                                ->orWhere(function (Builder $eventLocationQuery) use ($matchesEventVenueAddress): void {
                                    $matchesEventVenueAddress($eventLocationQuery);
                                });
                        });
                })
                ->orWhere(function (Builder $institutionQuery) use ($matchesOwnerAddress, $institutionMorphType): void {
                    $institutionQuery
                        ->whereNotNull('events.institution_id')
                        ->whereNull('events.default_venue_id')
                        ->whereDoesntHave('locations', function (Builder $locationQuery): void {
                            PrimaryLocationSql::constrainToSelected($locationQuery->getQuery());
                            $locationQuery->whereNotNull('venue_id');
                        });
                    $matchesOwnerAddress($institutionQuery, 'events.institution_id', $institutionMorphType);
                });
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsAfterTimestamp(array $filters, string $timeScope = 'upcoming'): ?int
    {
        return $this->startsAfterDateTime($filters, $timeScope)?->timestamp;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function startsBeforeTimestamp(array $filters): ?int
    {
        $startsBefore = $this->startsBeforeDateTime($filters);

        return $startsBefore?->timestamp;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    protected function startsOnLocalDateRange(array $filters): ?array
    {
        $startsOnLocalDateStart = $this->parseDateFilter($filters['starts_on_local_date'] ?? null, false);
        $startsOnLocalDateEnd = $this->parseDateFilter($filters['starts_on_local_date'] ?? null, true);

        if ($startsOnLocalDateStart instanceof CarbonInterface && $startsOnLocalDateEnd instanceof CarbonInterface) {
            return [$startsOnLocalDateStart, $startsOnLocalDateEnd];
        }

        return null;
    }

    protected function normalizeTimeScope(mixed $value): string
    {
        if (! is_string($value)) {
            return 'upcoming';
        }

        return in_array($value, ['upcoming', 'past', 'all'], true) ? $value : 'upcoming';
    }

    protected function parseDateFilter(mixed $value, bool $endOfDay): ?CarbonInterface
    {
        return UserDateTimeFormatter::parseUserDateToUtc($value, $endOfDay);
    }

    /**
     * @return array<int, mixed>
     */
    protected function normalizeArrayFilter(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter($values, fn (mixed $item): bool => $item !== null && $item !== ''));
    }

    /**
     * @return list<string>
     */
    private function uuidFilterValues(mixed $value): array
    {
        return array_values(array_filter(
            $this->normalizeArrayFilter($value),
            fn (string $candidate): bool => Str::isUuid($candidate),
        ));
    }

    /**
     * @return list<string>
     */
    protected function expandedReferenceIdsForFiltering(mixed $value): array
    {
        $referenceIds = $this->uuidFilterValues($value);

        return $this->referenceQueries->expandReferenceIdsForFiltering($referenceIds);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{ids: list<string>, has_author_filter: bool, has_id_filter: bool}
     */
    protected function resolvedReferenceFilter(array $filters): array
    {
        $hasIdFilter = $this->uuidFilterValues($filters['reference_ids'] ?? null) !== [];
        $referenceIds = $this->expandedReferenceIdsForFiltering($filters['reference_ids'] ?? null);
        $hasAuthorFilter = false;

        foreach ($this->normalizeArrayFilter($filters['reference_author_search'] ?? null) as $authorSearch) {
            if (! is_string($authorSearch) && ! is_numeric($authorSearch)) {
                continue;
            }

            $authorSearch = trim((string) $authorSearch);

            if ($authorSearch === '') {
                continue;
            }

            $hasAuthorFilter = true;
            $referenceIds = array_values(array_unique([
                ...$referenceIds,
                ...$this->referenceQueries->publicSearchIds($authorSearch),
            ]));
        }

        return [
            'ids' => $referenceIds,
            'has_author_filter' => $hasAuthorFilter,
            'has_id_filter' => $hasIdFilter,
        ];
    }

    /**
     * @return list<string>
     */
    protected function normalizeKeyPersonRoles(mixed $value): array
    {
        return collect($this->normalizeArrayFilter($value))
            ->map(fn (mixed $role): ?string => EventKeyPersonRole::tryFrom((string) $role)?->value)
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeBooleanFilter(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return null;
    }

    protected function normalizeTextFilter(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    protected function normalizePrayerTimeFilters(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $normalizedPrayerTimes = [];

        foreach ($values as $prayerTime) {
            if (! is_string($prayerTime)) {
                continue;
            }

            $normalized = mb_strtolower(trim($prayerTime));

            if ($normalized === '') {
                continue;
            }

            $enum = EventPrayerTime::tryFrom($normalized);
            $normalized = $enum instanceof EventPrayerTime ? $enum->value : $normalized;
            $normalizedPrayerTimes[$normalized] = $normalized;
        }

        return array_values($normalizedPrayerTimes);
    }

    protected function normalizeTimingModeFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, [TimingMode::Absolute->value, TimingMode::PrayerRelative->value], true)
            ? $value
            : null;
    }

    protected function normalizeTimeFilter(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        try {
            return now(UserDateTimeFormatter::resolveTimezone())
                ->setTimeFromTimeString($normalized)
                ->format('H:i');
        } catch (Throwable) {
            return null;
        }
    }

    protected function applyAbsoluteTimeRangeFilter(
        EventBuilder $queryBuilder,
        ?string $startsTimeFrom,
        ?string $startsTimeUntil
    ): void {
        if ($startsTimeFrom === null && $startsTimeUntil === null) {
            return;
        }

        $expression = $this->startsAtUserTimeSqlExpression($this->userUtcOffsetMinutes());

        if ($startsTimeFrom !== null && $startsTimeUntil !== null) {
            if ($startsTimeFrom <= $startsTimeUntil) {
                $queryBuilder
                    ->whereRaw("{$expression} >= ?", [$startsTimeFrom])
                    ->whereRaw("{$expression} <= ?", [$startsTimeUntil]);

                return;
            }

            $queryBuilder->where(function (Builder $timeQuery) use ($expression, $startsTimeFrom, $startsTimeUntil): void {
                $timeQuery
                    ->whereRaw("{$expression} >= ?", [$startsTimeFrom])
                    ->orWhereRaw("{$expression} <= ?", [$startsTimeUntil]);
            });

            return;
        }

        if ($startsTimeFrom !== null) {
            $queryBuilder->whereRaw("{$expression} >= ?", [$startsTimeFrom]);

            return;
        }

        $queryBuilder->whereRaw("{$expression} <= ?", [(string) $startsTimeUntil]);
    }

    protected function resolvePrayerReferenceFromFilter(string $prayerTime): ?PrayerReference
    {
        if (str_contains($prayerTime, 'jumaat') || str_contains($prayerTime, 'friday')) {
            return PrayerReference::FridayPrayer;
        }

        if (str_contains($prayerTime, 'maghrib')) {
            return PrayerReference::Maghrib;
        }

        if (str_contains($prayerTime, 'asar') || str_contains($prayerTime, 'asr')) {
            return PrayerReference::Asr;
        }

        if (str_contains($prayerTime, 'subuh') || str_contains($prayerTime, 'fajr')) {
            return PrayerReference::Fajr;
        }

        if (str_contains($prayerTime, 'zohor') || str_contains($prayerTime, 'zuhur') || str_contains($prayerTime, 'dhuhr')) {
            return PrayerReference::Dhuhr;
        }

        if (str_contains($prayerTime, 'isyak') || str_contains($prayerTime, 'isha')) {
            return PrayerReference::Isha;
        }

        return null;
    }

    protected function databaseDriver(): string
    {
        /** @var Connection $connection */
        $connection = Event::query()->getConnection();

        return $connection->getDriverName();
    }

    private function databaseLikeOperator(): string
    {
        return $this->databaseDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    private function startsAtUserTimeSqlExpression(int $offsetMinutes): string
    {
        return PrimaryOccurrenceSql::startsAtUserTimeExpression($offsetMinutes);
    }

    private function userUtcOffsetMinutes(): int
    {
        return now(UserDateTimeFormatter::resolveTimezone())->utcOffset();
    }

    private function normalizeSearchQuery(?string $query): ?string
    {
        if (! is_string($query)) {
            return null;
        }

        $normalizedQuery = trim($query);

        return $normalizedQuery === '' ? null : $normalizedQuery;
    }

    public function activeEventsForIds(array $eventIds, int $page, int $perPage): array
    {
        $events = Event::query()
            ->whereKey($eventIds)
            ->with([
                'institution:id,name,slug',
                'venue:id,name',
                'persons:id,name,slug',
            ])
            ->active()
            ->orderBy('starts_at')
            ->simplePaginate($perPage, page: max(1, $page));

        $items = [];
        $categoryPayloads = app(EventCategoryPresenter::class);

        foreach ($events->items() as $event) {
            $items[] = [
                'id' => (string) $event->getKey(),
                'title' => (string) $event->title,
                'slug' => (string) $event->slug,
                'status' => $this->enumValue($event->status),
                'visibility' => $this->enumValue($event->visibility),
                'starts_at' => $event->starts_at?->toJSON(),
                'ends_at' => $event->ends_at?->toJSON(),
                'timezone' => $event->timezone,
                'published_at' => $event->published_at?->toJSON(),
                'institution_id' => $event->institution_id,
                'venue_id' => $event->default_venue_id,
                'event_url' => $event->event_url,
                'live_url' => $event->live_url,
                'event_categories' => $categoryPayloads->forEvent($event),
                'event_format' => $this->enumValue($event->delivery_mode),
                'language' => $event->language,
                'institution' => $event->relationLoaded('institution') && $event->institution !== null
                    ? Arr::only($event->institution->toArray(), ['id', 'name', 'slug'])
                    : null,
                'venue' => $event->relationLoaded('venue') && $event->venue !== null
                    ? Arr::only($event->venue->toArray(), ['id', 'name'])
                    : null,
                'persons' => $event->relationLoaded('persons')
                    ? $event->persons
                        ->map(static fn ($person): array => Arr::only($person->toArray(), ['id', 'name', 'slug', 'pivot']))
                        ->values()
                        ->all()
                    : [],
            ];
        }

        $hasMore = $events->hasMorePages();

        return [
            'items' => $items,
            'page' => $events->currentPage(),
            'per_page' => $events->perPage(),
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $events->currentPage() + 1 : null,
        ];
    }

    public function institutionEventCounts(array $institutionIds): array
    {
        $institutionIds = $this->uniqueIds($institutionIds);

        if ($institutionIds === []) {
            return [];
        }

        $totals = $this->countByInstitution(
            Event::query()->whereIn('institution_id', $institutionIds),
        );

        $public = $this->countByInstitution(
            Event::query()
                ->whereIn('institution_id', $institutionIds)
                ->whereNotNull('events.published_at')
                ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
                ->where('events.visibility', EventVisibility::Public->value)
                ->whereHas('occurrences'),
        );

        $facts = [];

        foreach ($institutionIds as $institutionId) {
            $facts[$institutionId] = [
                'total' => $totals[$institutionId] ?? 0,
                'public' => $public[$institutionId] ?? 0,
            ];
        }

        return $facts;
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
        $query = Event::query()
            ->where('institution_id', $institutionId)
            ->with(['venue:id,name'])
            ->withCount(['registrations as workspace_registrations_count']);

        if ($search !== '') {
            $term = '%'.trim($search).'%';

            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->whereLike('title', $term)
                    ->orWhereHas('venue', fn (Builder $venueQuery) => $venueQuery->whereLike('name', $term));
            });
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($visibility !== 'all') {
            $query->where('visibility', $visibility);
        }

        match ($sort) {
            'starts_asc' => $query->orderBy('starts_at')->orderBy('title'),
            'title_asc' => $query->orderBy('title')->orderBy('starts_at', 'desc'),
            'title_desc' => $query->orderByDesc('title')->orderBy('starts_at', 'desc'),
            'registrations_desc' => $query->orderByDesc('workspace_registrations_count')->orderBy('starts_at', 'desc'),
            'pending_first' => $query
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderBy('starts_at')
                ->orderBy('title'),
            default => $query->orderByDesc('starts_at')->orderBy('title'),
        };

        /** @var Paginator<int, Event> $paginator */
        $paginator = $query->paginate($perPage, page: max(1, $page));

        return [
            'items' => $paginator->getCollection()
                ->map(fn (Event $event): array => [
                    'id' => $event->getKey(),
                    'title' => $event->title,
                    'slug' => $event->slug,
                    'status' => (string) $event->status,
                    'visibility' => $this->enumValue($event->visibility),
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'registrations_count' => (int) ($event->workspace_registrations_count ?? 0),
                    'venue' => $event->venue?->only(['id', 'name']),
                ])
                ->values()
                ->all(),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function institutionDirectoryEventColumns(): EventDirectorySqlProjection
    {
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');
        $now = now();

        $upcomingCount = DB::table("{$occurrencesTable} as public_occurrences")
            ->join('events', 'events.id', '=', 'public_occurrences.event_id')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value);
        $this->constrainPublicUpcomingOccurrences($upcomingCount, 'public_occurrences', $now);
        $upcomingCount->selectRaw('count(*)');

        $nextEventId = Event::query()
            ->join("{$occurrencesTable} as next_event_occurrences", 'next_event_occurrences.event_id', '=', 'events.id')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value);
        $this->constrainPublicUpcomingOccurrences($nextEventId, 'next_event_occurrences', $now);
        $nextEventId
            ->orderBy('next_event_occurrences.starts_at')
            ->orderBy('events.id')
            ->limit(1)
            ->select('events.id');

        return new EventDirectorySqlProjection(
            upcomingCount: new EventDirectorySqlColumn(
                sql: $upcomingCount->toSql(),
                bindings: $upcomingCount->getBindings(),
            ),
            nextEventId: new EventDirectorySqlColumn(
                sql: $nextEventId->toSql(),
                bindings: $nextEventId->getBindings(),
            ),
        );
    }

    /**
     * @param  list<string>  $eventIds
     * @return array<string, array{slug: string, title: string, next_event_starts_at: string}>
     */
    public function institutionNextEventDetails(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');
        $details = DB::table('events')
            ->join($occurrencesTable, "{$occurrencesTable}.event_id", '=', 'events.id')
            ->whereIn('events.id', $eventIds)
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value);
        $this->constrainPublicUpcomingOccurrences($details, $occurrencesTable);

        return $details
            ->groupBy('events.id', 'events.slug', 'events.title')
            ->selectRaw("events.id, events.slug, events.title, min(\"{$occurrencesTable}\".\"starts_at\") as next_event_starts_at")
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->id => [
                'slug' => (string) $row->slug,
                'title' => (string) $row->title,
                'next_event_starts_at' => (string) $row->next_event_starts_at,
            ]])
            ->all();
    }

    /**
     * Public occurrence state shared by directory counts, next-event
     * selection and next-event detail lookups.
     *
     * @param  Builder<Event>|QueryBuilder  $query
     */
    private function constrainPublicUpcomingOccurrences(
        Builder|QueryBuilder $query,
        string $occurrencesTable,
        ?CarbonInterface $now = null,
    ): void {
        $query
            ->whereIn("{$occurrencesTable}.status", Event::PUBLIC_SCHEDULE_STATUSES)
            ->whereIn("{$occurrencesTable}.visibility", Event::PUBLIC_SCHEDULE_VISIBILITIES)
            ->whereNotNull("{$occurrencesTable}.starts_at")
            ->where("{$occurrencesTable}.starts_at", '>=', $now ?? now());
    }

    public function institutionDirectoryFacts(array $institutionIds): array
    {
        $institutionIds = $this->uniqueIds($institutionIds);

        if ($institutionIds === []) {
            return [];
        }

        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');
        $now = now();

        $countsQuery = DB::table("{$occurrencesTable} as public_occurrences")
            ->join('events', 'events.id', '=', 'public_occurrences.event_id')
            ->whereIn('events.institution_id', $institutionIds)
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value);
        $this->constrainPublicUpcomingOccurrences($countsQuery, 'public_occurrences', $now);

        $counts = $countsQuery
            ->groupBy('events.institution_id')
            ->selectRaw('events.institution_id as institution_id, count(*) as aggregate')
            ->pluck('aggregate', 'institution_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(string) $id => (int) $count])
            ->all();

        $nextEventIds = $this->nextPublicEventIds($institutionIds, $occurrencesTable, $now);
        $details = $this->nextPublicEventDetails(array_values($nextEventIds), $occurrencesTable, $now);

        $facts = [];

        foreach ($institutionIds as $institutionId) {
            $eventId = $nextEventIds[$institutionId] ?? null;
            $detail = $eventId !== null ? ($details[$eventId] ?? null) : null;

            $facts[$institutionId] = [
                'events_count' => $counts[$institutionId] ?? 0,
                'next_event' => $detail,
            ];
        }

        return $facts;
    }

    /**
     * @param  Builder<Event>  $query
     * @return array<string, int>
     */
    private function countByInstitution(Builder $query): array
    {
        return $query
            ->groupBy('institution_id')
            ->selectRaw('institution_id, count(*) as aggregate')
            ->pluck('aggregate', 'institution_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(string) $id => (int) $count])
            ->all();
    }

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, string>
     */
    private function nextPublicEventIds(array $institutionIds, string $occurrencesTable, CarbonInterface $now): array
    {
        $query = DB::table('events')
            ->join("{$occurrencesTable} as next_event_occurrences", 'next_event_occurrences.event_id', '=', 'events.id')
            ->whereIn('events.institution_id', $institutionIds)
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value);
        $this->constrainPublicUpcomingOccurrences($query, 'next_event_occurrences', $now);

        $rows = $query
            ->orderBy('next_event_occurrences.starts_at')
            ->orderBy('events.id')
            ->get(['events.institution_id', 'events.id']);

        $nextIds = [];

        foreach ($rows as $row) {
            $institutionId = (string) $row->institution_id;

            if (! array_key_exists($institutionId, $nextIds)) {
                $nextIds[$institutionId] = (string) $row->id;
            }
        }

        return $nextIds;
    }

    /**
     * @param  list<string>  $eventIds
     * @return array<string, array{slug: string, title: string, starts_at: string}>
     */
    private function nextPublicEventDetails(array $eventIds, string $occurrencesTable, CarbonInterface $now): array
    {
        if ($eventIds === []) {
            return [];
        }

        $query = DB::table('events')
            ->join($occurrencesTable, "{$occurrencesTable}.event_id", '=', 'events.id')
            ->whereIn('events.id', $eventIds)
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value);
        $this->constrainPublicUpcomingOccurrences($query, $occurrencesTable, $now);

        return $query
            ->groupBy('events.id', 'events.slug', 'events.title')
            ->selectRaw("events.id, events.slug, events.title, min(\"{$occurrencesTable}\".\"starts_at\") as next_event_starts_at")
            ->get()
            ->mapWithKeys(static fn (object $row): array => [
                (string) $row->id => [
                    'slug' => (string) $row->slug,
                    'title' => (string) $row->title,
                    'starts_at' => (string) $row->next_event_starts_at,
                ],
            ])
            ->all();
    }

    public function findEvent(string $eventId): ?Event
    {
        return Event::query()->find($eventId);
    }

    public function shareIdentityBySlug(string $slug): ?array
    {
        $event = Event::query()->where('slug', $slug)->first(['id', 'slug', 'title']);

        if (! $event instanceof Event) {
            return null;
        }

        return [
            'id' => (string) $event->getKey(),
            'slug' => (string) $event->slug,
            'title' => (string) $event->title,
        ];
    }

    /**
     * Hydrate current public parent events for selected public schedule ids.
     *
     * @param  list<string>  $eventIds
     * @param  list<string>  $relations
     * @return EloquentCollection<string, Event>
     */
    private function hydrateEventsForIds(array $eventIds, array $relations): EloquentCollection
    {
        return $this->constrainCurrentPublicEventState(
            Event::query()
                ->with($relations)
                ->whereKey($eventIds),
        )
            ->get()
            ->keyBy('id');
    }

    public function eventStatus(string $eventId): ?string
    {
        $event = Event::query()->find($eventId);

        return $event instanceof Event ? (string) $event->status : null;
    }

    public function isPubliclyReachable(string $eventId): bool
    {
        $event = Event::query()->find($eventId);

        return $event instanceof Event && $event->isPubliclyReachable();
    }

    public function eventsForOwner(string $ownerType, string $ownerId, int $limit): EloquentCollection
    {
        return Event::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function plannerEventsForIds(array $eventIds, bool $activeOnly): EloquentCollection
    {
        return Event::query()
            ->whereKey($eventIds)
            ->when($activeOnly, fn (EventBuilder $query): EventBuilder => $query->active())
            ->with(['media', 'institution', 'institution.media', 'venue'])
            ->orderBy('starts_at')
            ->get();
    }

    public function recommendedPlannerEvent(array $excludedEventIds, string $fromUtc): ?Event
    {
        return Event::query()
            ->active()
            ->with(['media', 'institution', 'institution.media', 'venue'])
            ->when($excludedEventIds !== [], fn (EventBuilder $query): EventBuilder => $query->whereNotIn('id', $excludedEventIds))
            ->where('starts_at', '>=', CarbonImmutable::parse($fromUtc))
            ->orderBy('starts_at')
            ->first();
    }

    public function eventsWithAdminRelations(array $eventIds): EloquentCollection
    {
        return Event::query()
            ->whereKey($eventIds)
            ->with(['institution:id,name', 'venue:id,name'])
            ->get();
    }

    public function eventTable(): string
    {
        return (new Event)->getTable();
    }

    public function keyPersonTable(): string
    {
        return (new EventKeyPerson)->getTable();
    }

    public function seriesTable(): string
    {
        return (new Series)->getTable();
    }

    public function institutionTableQuery(string $institutionId): EventBuilder
    {
        return Event::query()
            ->where('institution_id', $institutionId)
            ->with([
                'primaryLocation.venueSpace:id,name',
                'persons:id,name',
                'references.parentReference.parentReference',
            ])
            ->withCount(['registrations as dashboard_registrations_count']);
    }

    public function recentInstitutionEventCards(string $institutionId, int $limit): EloquentCollection
    {
        $query = $this->institutionEventCardQuery($institutionId);
        $upcoming = (clone $query)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        if ($upcoming->isNotEmpty()) {
            return $upcoming;
        }

        return $query
            ->orderByDesc('starts_at')
            ->orderBy('title')
            ->limit($limit)
            ->get();
    }

    public function pendingInstitutionEventCards(
        string $institutionId,
        string $search,
        string $status,
        string $visibility,
        int $limit,
    ): EloquentCollection {
        return $this->applyInstitutionEventCardFilters(
            $this->institutionEventCardQuery($institutionId),
            $search,
            $status,
            $visibility,
        )
            ->whereIn('status', ['pending', 'needs_changes'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('starts_at')
            ->orderBy('title')
            ->limit($limit)
            ->get();
    }

    private function institutionEventCardQuery(string $institutionId): EventBuilder
    {
        return Event::query()
            ->where('institution_id', $institutionId)
            ->with([
                'institution.media',
                'media',
                'primaryLocation.venueSpace:id,name',
                'venue:id,name',
            ])
            ->withCount(['registrations as dashboard_registrations_count']);
    }

    private function applyInstitutionEventCardFilters(
        EventBuilder $query,
        string $search,
        string $status,
        string $visibility,
    ): EventBuilder {
        $search = trim($search);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->whereLike('title', $like)
                    ->orWhereHas('venue', fn (Builder $venueQuery): Builder => $venueQuery->whereLike('name', $like));
            });
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($visibility !== 'all') {
            $query->where('visibility', $visibility);
        }

        return $query;
    }

    public function institutionRegistrationCount(string $institutionId): int
    {
        return (int) Event::query()
            ->where('institution_id', $institutionId)
            ->withCount('registrations')
            ->get()
            ->sum(fn (Event $event): int => (int) ($event->registrations_count ?? 0));
    }

    public function emptyEventsQuery(): EventBuilder
    {
        return Event::query()->whereRaw('1 = 0');
    }

    public function personDashboardEventStats(string $personId): array
    {
        $eventsTable = $this->eventTable();
        $events = $this->personDashboardEventQuery($personId);

        return [
            'total' => (clone $events)->count(),
            'upcoming' => (clone $events)
                ->whereNotNull("{$eventsTable}.starts_at")
                ->where("{$eventsTable}.starts_at", '>=', now())
                ->count(),
            'needs_attention' => (clone $events)
                ->whereIn("{$eventsTable}.status", ['draft', 'pending', 'needs_changes'])
                ->count(),
        ];
    }

    public function personDashboardEventsPage(
        string $personId,
        string $search,
        string $status,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $eventsTable = $this->eventTable();
        $query = $this->personDashboardEventQuery($personId)
            ->with([
                'institution:id,name',
                'primaryLocation.venueSpace:id,name',
            ])
            ->withCount(['registrations as dashboard_registrations_count']);
        $search = trim($search);

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function (Builder $eventQuery) use ($eventsTable, $like): void {
                $eventQuery
                    ->whereLike("{$eventsTable}.title", $like)
                    ->orWhereHas('institution', fn (Builder $institutionQuery): Builder => $institutionQuery->whereLike('name', $like));
            });
        }

        if ($status !== 'all') {
            $query->where("{$eventsTable}.status", $status);
        }

        return $query
            ->orderByRaw("CASE WHEN {$eventsTable}.status IN ('draft', 'pending', 'needs_changes') THEN 0 ELSE 1 END")
            ->orderByDesc("{$eventsTable}.starts_at")
            ->orderByDesc("{$eventsTable}.updated_at")
            ->paginate(
                perPage: $perPage,
                pageName: 'person_events_page',
                page: $page,
            );
    }

    private function personDashboardEventQuery(string $personId): EventBuilder
    {
        return Event::query()->whereHas('persons', function (Builder $personQuery) use ($personId): void {
            $personQuery->whereKey($personId);
        });
    }

    public function referenceEventsQuery(array $referenceIds, array $venueEagerLoads): EventBuilder
    {
        return Event::query()
            ->where(function (Builder $referenceFilter) use ($referenceIds): void {
                $referenceFilter
                    ->whereHas('references', fn (Builder $references): Builder => $references
                        ->whereIn('references.id', $referenceIds))
                    ->orWhereHas('occurrences', function (Builder $occurrences) use ($referenceIds): void {
                        $occurrence = new EventOccurrence;
                        $occurrences
                            ->whereIn($occurrence->qualifyColumn('status'), Event::PUBLIC_SCHEDULE_STATUSES)
                            ->whereIn($occurrence->qualifyColumn('visibility'), Event::PUBLIC_SCHEDULE_VISIBILITIES)
                            ->whereHas('references', function (Builder $referenceRows) use ($referenceIds): void {
                                $referenceRows
                                    ->where('visibility', 'public')
                                    ->whereHasMorph('referenceable', [MorphModelResolver::resolve('reference')], function (Builder $references) use ($referenceIds): void {
                                        app(PublicReferenceConstraint::class)->apply($references)
                                            ->whereIn('references.id', $referenceIds);
                                    });
                            });
                    });
            })
            ->active()
            ->reorder()
            ->with([
                ...$venueEagerLoads,
                'persons.media',
                'persons.titleAssignments.title.category',
                'keyPeople.person',
                'references' => function (BelongsToMany $references): void {
                    app(PublicReferenceConstraint::class)->apply($references->getQuery());
                },
                'media',
                'institution.media',
                'classifications.term',
                'primaryOccurrence',
                'displayOccurrence.media',
                'timeExpressions',
                'links',
                'occurrences.references',
            ]);
    }

    public function institutionSpeakerCount(string $institutionId): int
    {
        $endInstant = app(EventScheduleSql::class)->endInstantSql((new Event)->getTable());

        $eventIds = Event::query()
            ->where('institution_id', $institutionId)
            ->whereIn('status', Event::PUBLIC_STATUSES)
            ->whereRaw($endInstant.' >= ?', [now()])
            ->select('id');

        return (int) DB::table('event_involvements')
            ->where('role_code', EventKeyPersonRole::Speaker->value)
            ->where('involveable_type', 'person')
            ->whereNotNull('involveable_id')
            ->whereIn('event_id', $eventIds)
            ->distinct('involveable_id')
            ->count();
    }

    public function institutionEventStats(array $institutionIds): array
    {
        $institutionIds = $this->uniqueIds($institutionIds);

        if ($institutionIds === []) {
            return [];
        }

        $table = (new Event)->getTable();
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');
        $statuses = $this->stringValues(Event::PUBLIC_STATUSES);
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));

        $rows = DB::table($table)
            ->whereIn('institution_id', $institutionIds)
            ->groupBy('institution_id')
            ->select('institution_id')
            ->selectRaw('count(*) as total_count')
            ->selectRaw(
                "sum(case when published_at is not null and status in ({$statusPlaceholders}) and visibility = ? and exists (select 1 from {$occurrencesTable} where {$occurrencesTable}.event_id = {$table}.id) then 1 else 0 end) as public_count",
                [...$statuses, EventVisibility::Public->value],
            )
            ->selectRaw(
                "sum(case when exists (select 1 from {$occurrencesTable} where {$occurrencesTable}.event_id = {$table}.id and {$occurrencesTable}.starts_at is not null and {$occurrencesTable}.starts_at >= ? and {$occurrencesTable}.starts_at <= ?) then 1 else 0 end) as upcoming_count",
                [now(), now()->addDays(30)],
            )
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as draft_count', ['draft'])
            ->selectRaw('sum(case when status in (?, ?) then 1 else 0 end) as pending_count', ['pending', 'needs_changes'])
            ->get();

        $stats = [];

        foreach ($institutionIds as $institutionId) {
            $stats[$institutionId] = [
                'total' => 0,
                'public' => 0,
                'upcoming' => 0,
                'draft' => 0,
                'pending' => 0,
            ];
        }

        foreach ($rows as $row) {
            $stats[(string) $row->institution_id] = [
                'total' => (int) $row->total_count,
                'public' => (int) $row->public_count,
                'upcoming' => (int) $row->upcoming_count,
                'draft' => (int) $row->draft_count,
                'pending' => (int) $row->pending_count,
            ];
        }

        return $stats;
    }

    /**
     * @param  array<string, list<string>>  $referenceFamilies
     * @return array<string, int>
     */
    public function publicEventCountsForReferenceFamilies(array $referenceFamilies): array
    {
        $familyMap = null;
        $counts = [];
        $uuidCast = DB::connection()->getDriverName() === 'pgsql' ? '::uuid' : '';

        foreach ($referenceFamilies as $familyId => $ids) {
            $counts[(string) $familyId] = 0;

            $familyReferenceIds = array_values(array_unique(array_filter(
                $ids,
                static fn (mixed $referenceId): bool => is_string($referenceId) && $referenceId !== '',
            )));

            foreach ($familyReferenceIds as $referenceId) {
                $familyRow = DB::query()->selectRaw("?{$uuidCast} as family_id, ?{$uuidCast} as reference_id", [(string) $familyId, $referenceId]);

                if ($familyMap === null) {
                    $familyMap = $familyRow;
                } else {
                    $familyMap->unionAll($familyRow);
                }
            }
        }

        if ($familyMap === null) {
            return [];
        }

        $pivotTable = (string) config('events.database.tables.event_references', 'event_references');
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');

        $rows = DB::query()
            ->fromSub($familyMap, 'reference_families')
            ->join("{$pivotTable} as reference_links", 'reference_links.referenceable_id', '=', 'reference_families.reference_id')
            ->join('events', 'events.id', '=', 'reference_links.event_id')
            ->where('reference_links.referenceable_type', $this->referenceQueries->referenceMorphType())
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereExists(static function (QueryBuilder $occurrences) use ($occurrencesTable): void {
                $occurrences->selectRaw('1')
                    ->from($occurrencesTable)
                    ->whereColumn("{$occurrencesTable}.event_id", 'events.id');
            })
            ->select('reference_families.family_id')
            ->selectRaw('COUNT(DISTINCT events.id) as event_count')
            ->groupBy('reference_families.family_id')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->family_id] = (int) $row->event_count;
        }

        return $counts;
    }

    /**
     * @param  array<string, list<string>>  $referenceFamilies
     * @return array<string, array{next_event_id: ?string, upcoming_events_count: int}>
     */
    public function referenceDirectoryFacts(array $referenceFamilies): array
    {
        $uniqueIds = [];

        foreach ($referenceFamilies as $ids) {
            foreach ($ids as $referenceId) {
                if (is_string($referenceId) && $referenceId !== '') {
                    $uniqueIds[$referenceId] = true;
                }
            }
        }

        if ($uniqueIds === []) {
            return [];
        }

        $pivotTable = (string) config('events.database.tables.event_references', 'event_references');
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');

        $rows = DB::table('events')
            ->join("{$pivotTable} as reference_links", 'reference_links.event_id', '=', 'events.id')
            ->join("{$occurrencesTable} as upcoming_occurrences", 'upcoming_occurrences.event_id', '=', 'events.id')
            ->where('reference_links.referenceable_type', $this->referenceQueries->referenceMorphType())
            ->whereIn('reference_links.referenceable_id', array_keys($uniqueIds))
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereIn('upcoming_occurrences.status', $this->stringValues(Event::PUBLIC_SCHEDULE_STATUSES))
            ->whereIn('upcoming_occurrences.visibility', $this->stringValues(Event::PUBLIC_SCHEDULE_VISIBILITIES))
            ->whereNotNull('upcoming_occurrences.starts_at')
            ->where('upcoming_occurrences.starts_at', '>=', now())
            ->distinct()
            ->select([
                'reference_links.referenceable_id as reference_id',
                'events.id as event_id',
                'upcoming_occurrences.id as occurrence_id',
                'upcoming_occurrences.starts_at as occurrence_starts_at',
            ])
            ->get();

        $byReference = [];

        foreach ($rows as $row) {
            $referenceId = (string) $row->reference_id;
            $eventId = (string) $row->event_id;
            $occurrenceId = (string) $row->occurrence_id;
            $startsAt = (string) $row->occurrence_starts_at;

            $byReference[$referenceId]['event_starts'][$eventId] = isset($byReference[$referenceId]['event_starts'][$eventId])
                ? min($byReference[$referenceId]['event_starts'][$eventId], $startsAt)
                : $startsAt;
            $byReference[$referenceId]['occurrences'][$occurrenceId] = true;
        }

        $facts = [];

        foreach ($referenceFamilies as $rootId => $ids) {
            $eventStarts = [];
            $occurrences = [];

            foreach ($ids as $referenceId) {
                foreach ($byReference[$referenceId]['event_starts'] ?? [] as $eventId => $startsAt) {
                    $eventStarts[$eventId] = isset($eventStarts[$eventId]) ? min($eventStarts[$eventId], $startsAt) : $startsAt;
                }

                foreach (array_keys($byReference[$referenceId]['occurrences'] ?? []) as $occurrenceId) {
                    $occurrences[$occurrenceId] = true;
                }
            }

            $nextEventId = null;
            $nextStartsAt = null;

            foreach ($eventStarts as $eventId => $startsAt) {
                if ($nextStartsAt === null || $startsAt < $nextStartsAt
                    || ($startsAt === $nextStartsAt && $nextEventId !== null && $eventId < $nextEventId)) {
                    $nextStartsAt = $startsAt;
                    $nextEventId = $eventId;
                }
            }

            $facts[(string) $rootId] = [
                'next_event_id' => $nextEventId,
                'upcoming_events_count' => count($occurrences),
            ];
        }

        return $facts;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            $ids,
            static fn (string $id): bool => $id !== '',
        )));
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

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
