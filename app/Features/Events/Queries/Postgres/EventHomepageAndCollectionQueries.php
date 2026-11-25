<?php

declare(strict_types=1);

namespace App\Features\Events\Queries\Postgres;

use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Venues\Models\VenueSpace;
use App\Features\Events\Api\Data\AdminEventCard;
use App\Features\Events\Api\Data\InstitutionDashboardEventCard;
use App\Features\Events\Api\Data\OrganizationWorkspaceEvent;
use App\Features\Events\Api\Data\PersonDashboardEvent;
use App\Features\Events\Api\Data\PersonDashboardEventPage;
use App\Features\Events\Api\Data\PlannerEventCard;
use App\Features\Events\Api\Enums\EventKeyPersonRole;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventDirectorySqlColumn;
use App\Features\Events\Api\EventDirectorySqlProjection;
use App\Features\Events\Api\EventScheduleFactSet;
use App\Features\Events\Api\EventScheduleSql;
use App\Features\Events\Models\Builders\EventBuilder;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventKeyPerson;
use App\Features\Events\Models\Series;
use App\Features\Events\Queries\EventCategoryPresenter;
use App\Features\Events\Queries\EventListPayloadPresenter;
use App\Features\Events\Queries\PublicReferenceConstraint;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\SpaceLocation;
use App\Features\References\Api\ReferenceQueries;
use App\Models\User;
use App\Platform\Models\MorphModelResolver;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Internal query collaborator for homepage selections, directory projections,
 * collection cards, and dashboard/workspace event reads.
 */
final readonly class EventHomepageAndCollectionQueries
{
    public function __construct(
        private EventFilterQueryBuilder $filterQueryBuilder,
        private EventScheduleOccurrencePaginator $schedulePaginator,
        private EventScheduleHydrator $scheduleHydrator,
        private PersonLookup $personLookup,
        private ReferenceQueries $referenceQueries,
        private EventListPayloadPresenter $eventListPayloads,
        private EventScheduleSql $eventScheduleSql,
        private PublicReferenceConstraint $publicReferenceConstraint,
        private EventCategoryPresenter $eventCategories,
        private SpaceLocation $spaceLocation,
    ) {}

    /**
     * @return list<array{event_id: string, occurrence_id: string}>
     */
    public function homepageFeaturedOccurrences(int $limit = 9): array
    {
        $occurrencesTable = (new EventOccurrence)->getTable();
        $nowUtc = UserDateTimeFormatter::userNow()->utc();

        return DB::table("{$occurrencesTable} as occ")
            ->join('events as ev', 'ev.id', '=', 'occ.event_id')
            ->where('ev.status', 'approved')
            ->where('ev.visibility', EventVisibility::Public->value)
            ->whereNotNull('ev.published_at')
            ->where('occ.is_featured', true)
            ->whereIn('occ.status', $this->stringValues(Event::PUBLIC_SCHEDULE_STATUSES))
            ->whereIn('occ.visibility', $this->stringValues(Event::PUBLIC_SCHEDULE_VISIBILITIES))
            ->whereNotNull('occ.starts_at')
            ->whereRaw('coalesce(occ.ends_at, occ.starts_at) >= ?', [$nowUtc])
            ->orderBy('occ.starts_at')
            ->orderByRaw("trim(coalesce(nullif(trim(coalesce(occ.title, '')), ''), ev.title)) asc")
            ->orderBy('occ.id')
            ->limit($limit)
            ->get(['occ.event_id', 'occ.id as occurrence_id'])
            ->map(static fn (object $row): array => [
                'event_id' => (string) $row->event_id,
                'occurrence_id' => (string) $row->occurrence_id,
            ])
            ->values()
            ->all();
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
        if ($personIds === [] && $institutionIds === [] && $referenceIds === []) {
            return [];
        }

        $occurrencesTable = (new EventOccurrence)->getTable();
        $nowUtc = UserDateTimeFormatter::userNow()->utc();

        return DB::table("{$occurrencesTable} as occ")
            ->join('events as ev', 'ev.id', '=', 'occ.event_id')
            ->where('ev.status', 'approved')
            ->where('ev.visibility', EventVisibility::Public->value)
            ->whereNotNull('ev.published_at')
            ->where('occ.is_featured', false)
            ->whereIn('occ.status', $this->stringValues(Event::PUBLIC_SCHEDULE_STATUSES))
            ->whereIn('occ.visibility', $this->stringValues(Event::PUBLIC_SCHEDULE_VISIBILITIES))
            ->whereNotNull('occ.starts_at')
            ->whereRaw('coalesce(occ.ends_at, occ.starts_at) >= ?', [$nowUtc])
            ->where('occ.starts_at', '<=', $startsBeforeUtc)
            ->when($excludeOccurrenceIds !== [], static fn (QueryBuilder $query): QueryBuilder => $query->whereNotIn('occ.id', $excludeOccurrenceIds))
            ->where(function (QueryBuilder $followQuery) use ($personIds, $institutionIds, $referenceIds): void {
                if ($personIds !== []) {
                    $followQuery->orWhereExists(static function (QueryBuilder $sub) use ($personIds): void {
                        $sub->selectRaw('1')
                            ->from('event_involvements as follow_involvements')
                            ->whereColumn('follow_involvements.event_id', 'ev.id')
                            ->whereNull('follow_involvements.event_occurrence_id')
                            ->whereNull('follow_involvements.event_session_id')
                            ->where('follow_involvements.involveable_type', 'person')
                            ->where('follow_involvements.role_code', EventKeyPersonRole::Speaker->value)
                            ->whereIn('follow_involvements.involveable_id', $personIds);
                    });
                }

                if ($institutionIds !== []) {
                    $followQuery->orWhereIn('ev.institution_id', $institutionIds);
                }

                if ($referenceIds !== []) {
                    $followQuery->orWhereExists(function (QueryBuilder $sub) use ($referenceIds): void {
                        $sub->selectRaw('1')
                            ->from('event_references as follow_references')
                            ->join('references as follow_reference_rows', 'follow_reference_rows.id', '=', 'follow_references.referenceable_id')
                            ->whereColumn('follow_references.event_id', 'ev.id')
                            ->where('follow_references.referenceable_type', 'reference')
                            ->whereIn('follow_references.referenceable_id', $referenceIds);

                        $this->publicReferenceConstraint->apply($sub, 'follow_reference_rows');
                    });
                }
            })
            ->orderBy('occ.starts_at')
            ->limit($limit)
            ->get(['occ.event_id', 'occ.id as occurrence_id'])
            ->map(static fn (object $row): array => [
                'event_id' => (string) $row->event_id,
                'occurrence_id' => (string) $row->occurrence_id,
            ])
            ->values()
            ->all();
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
            ->with($this->scheduleHydrator->scheduleFactRelations())
            ->get();

        return $this->schedulePaginator->scheduleFactSet($events);
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
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forInstitution(
        string $institutionId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->eventCards(
            Event::query()
                ->where('institution_id', $institutionId)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution.media',
                    'venue.addresses.country',
                    'persons.media',
                    'keyPeople.person',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forPerson(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        $involvementsTable = (new EventKeyPerson)->getTable();

        return $this->eventCards(
            Event::query()
                ->join($involvementsTable.' as person_involvements', 'person_involvements.event_id', '=', 'events.id')
                ->where('person_involvements.involveable_type', 'person')
                ->where('person_involvements.involveable_id', $personId)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution',
                    'institution.media',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'persons.media',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('person_involvements.sort_order')
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forVenue(
        string $venueId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        return $this->eventCards(
            Event::query()
                ->where('default_venue_id', $venueId)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'keyPeople.person.media',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    /**
     * @param  list<string>  $referenceIds
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forReferences(
        array $referenceIds,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        if ($referenceIds === []) {
            return ['items' => [], 'total' => 0];
        }

        return $this->eventCards(
            Event::query()
                ->active()
                ->whereHas('references', function (Builder $referenceQuery) use ($referenceIds): void {
                    $this->publicReferenceConstraint->apply($referenceQuery)
                        ->whereIn('references.id', $referenceIds);
                })
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution',
                    'institution.media',
                    'institution.addresses.country',
                    'persons.media',
                    'venue.addresses.country',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forSeries(
        string $seriesId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        $seriesItemsTable = (string) config('events.database.tables.event_series_items', 'event_series_items');

        return $this->eventCards(
            Event::query()
                ->join($seriesItemsTable.' as series_items', 'series_items.event_id', '=', 'events.id')
                ->where('series_items.event_series_id', $seriesId)
                ->where('series_items.seriesable_type', Event::class)
                ->active()
                ->whereRaw($this->directionSql($past), [$now ?? now()])
                ->with([
                    'institution',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'persons.media',
                    'media',
                    'references' => fn (BelongsToMany $query) => $query->active(),
                    'primaryOccurrence',
                    'classifications.term',
                    'timeExpressions',
                ])
                ->orderBy('series_items.sort_order')
                ->orderBy('starts_at', $past ? 'desc' : 'asc'),
            $perPage,
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function personParticipations(
        string $personId,
        bool $past,
        int $perPage,
        ?\DateTimeInterface $now = null,
    ): array {
        $now ??= now();
        $involvementsTable = (new EventKeyPerson)->getTable();
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');

        $query = EventKeyPerson::query()
            ->where('involveable_type', 'person')
            ->where('involveable_id', $personId)
            ->where('role_code', '!=', EventKeyPersonRole::Speaker->value)
            ->where('visibility', 'public')
            ->whereHas('event', function (Builder $query) use ($now, $past): void {
                $query
                    ->whereNotNull('events.published_at')
                    ->whereIn('events.status', Event::PUBLIC_STATUSES)
                    ->where('events.visibility', EventVisibility::Public)
                    ->whereHas('occurrences')
                    ->whereRaw($this->directionSql($past), [$now]);
            });

        $total = (clone $query)->count();

        /** @var Collection<int, EventKeyPerson> $matches */
        $matches = $query
            ->select("{$involvementsTable}.*")
            ->selectSub(function ($query) use ($occurrencesTable, $involvementsTable): void {
                $query->select("{$occurrencesTable}.starts_at")
                    ->from($occurrencesTable)
                    ->whereColumn("{$occurrencesTable}.event_id", "{$involvementsTable}.event_id")
                    ->orderBy("{$occurrencesTable}.starts_at")
                    ->orderBy("{$occurrencesTable}.created_at")
                    ->orderBy("{$occurrencesTable}.id")
                    ->limit(1);
            }, '__ordered_starts_at')
            ->with([
                'event' => fn ($query) => $query->with([
                    'primaryOccurrence',
                    'displayOccurrence.media',
                    'classifications.term',
                    'timeExpressions',
                    'institution.media',
                    'institution.addresses.country',
                    'venue.addresses.country',
                    'persons.media',
                    'media',
                    'references' => function (BelongsToMany $references): void {
                        $this->publicReferenceConstraint->apply($references->getQuery());
                    },
                ]),
            ])
            ->reorder()
            ->orderBy('__ordered_starts_at', $past ? 'desc' : 'asc')
            ->orderBy("{$involvementsTable}.sort_order")
            ->orderBy("{$involvementsTable}.id")
            ->take($perPage)
            ->get();

        return [
            'items' => $matches
                ->map(fn (EventKeyPerson $keyPerson): array => $this->participationData($keyPerson))
                ->all(),
            'total' => $total,
        ];
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
                    $this->publicReferenceConstraint->apply($references->getQuery());
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
     * @param  list<string>  $eventIds
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     has_more: bool,
     *     next_page: int|null
     * }
     */
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
                'event_categories' => $this->eventCategories->forEvent($event),
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

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, array{total: int, public: int}>
     */
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
     * @param  list<string>  $institutionIds
     * @return array<string, array{
     *     events_count: int,
     *     next_event: array{slug: string, title: string, starts_at: string}|null
     * }>
     */
    public function institutionDirectoryFacts(array $institutionIds): array
    {
        $institutionIds = $this->uniqueIds($institutionIds);

        if ($institutionIds === []) {
            return [];
        }

        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');
        $now = now();

        $ranked = DB::table('events')
            ->join("{$occurrencesTable} as occ", 'occ.event_id', '=', 'events.id')
            ->whereIn('events.institution_id', $institutionIds)
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value);
        $this->constrainPublicUpcomingOccurrences($ranked, 'occ', $now);

        $ranked
            ->select([
                'events.institution_id',
                'events.slug',
                'events.title',
                'occ.starts_at',
            ])
            ->selectRaw('row_number() over (partition by events.institution_id order by occ.starts_at asc, events.id asc) as rn')
            ->selectRaw('count(*) over (partition by events.institution_id) as events_count');

        $rows = DB::query()
            ->fromSub($ranked, 'ranked_institution_events')
            ->where('rn', 1)
            ->get();

        $facts = [];

        foreach ($institutionIds as $institutionId) {
            $facts[$institutionId] = [
                'events_count' => 0,
                'next_event' => null,
            ];
        }

        foreach ($rows as $row) {
            $facts[(string) $row->institution_id] = [
                'events_count' => (int) $row->events_count,
                'next_event' => [
                    'slug' => (string) $row->slug,
                    'title' => (string) $row->title,
                    'starts_at' => (string) $row->starts_at,
                ],
            ];
        }

        return $facts;
    }

    /** @return array{id: string, title: string}|null */
    public function shareIdentityById(string $eventId): ?array
    {
        $event = Event::query()->whereKey($eventId)->first(['id', 'title']);

        return $event instanceof Event ? [
            'id' => (string) $event->getKey(),
            'title' => (string) $event->title,
        ] : null;
    }

    /**
     * @return array{id: string, slug: string, title: string}|null
     */
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

    /** @return list<OrganizationWorkspaceEvent> */
    public function organizationWorkspaceEvents(string $ownerType, string $ownerId, int $limit): array
    {
        $events = Event::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->latest('created_at')
            ->limit($limit)
            ->get();
        $actor = auth()->user();
        $items = [];

        foreach ($events as $event) {
            $publiclyReachable = $event->isPubliclyReachable();
            $visibility = $event->visibility;

            $items[] = new OrganizationWorkspaceEvent(
                id: (string) $event->getKey(),
                title: (string) $event->title,
                status: (string) $event->status,
                visibility: $visibility instanceof BackedEnum ? (string) $visibility->value : (string) $visibility,
                publiclyReachable: $publiclyReachable,
                publicUrl: $publiclyReachable ? route('events.show', $event) : null,
                canEdit: $actor instanceof User && $actor->can('update', $event),
                canManageAdmissions: $actor instanceof User && $actor->can('manageAdmissions', $event),
            );
        }

        return $items;
    }

    /**
     * @param  list<string>  $eventIds
     * @return list<PlannerEventCard>
     */
    public function plannerEventCardsForIds(array $eventIds, bool $activeOnly): array
    {
        if ($eventIds === []) {
            return [];
        }

        return Event::query()
            ->whereKey($eventIds)
            ->when($activeOnly, fn (EventBuilder $query): EventBuilder => $query->active())
            ->with(['media', 'institution', 'institution.media', 'venue'])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Event $event): PlannerEventCard => $this->plannerCard($event))
            ->values()
            ->all();
    }

    /** @param list<string> $excludedEventIds */
    public function recommendedPlannerEventCard(array $excludedEventIds, string $fromUtc): ?PlannerEventCard
    {
        $event = Event::query()
            ->active()
            ->with(['media', 'institution', 'institution.media', 'venue'])
            ->when($excludedEventIds !== [], fn (EventBuilder $query): EventBuilder => $query->whereNotIn('id', $excludedEventIds))
            ->where('starts_at', '>=', CarbonImmutable::parse($fromUtc))
            ->orderBy('starts_at')
            ->first();

        return $event instanceof Event ? $this->plannerCard($event) : null;
    }

    /**
     * @param  list<string>  $eventIds
     * @return list<AdminEventCard>
     */
    public function adminEventCardsForIds(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return Event::query()
            ->whereKey($eventIds)
            ->with(['institution:id,name', 'venue:id,name'])
            ->get()
            ->map(static function (Event $event): AdminEventCard {
                $start = $event->starts_at;

                return new AdminEventCard(
                    id: (string) $event->getKey(),
                    title: (string) $event->title,
                    status: (string) $event->status,
                    startsAt: $start instanceof CarbonInterface ? CarbonImmutable::instance($start) : null,
                    institutionName: $event->institution?->name,
                    venueName: $event->venue?->name,
                );
            })
            ->values()
            ->all();
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

    /** @return list<InstitutionDashboardEventCard> */
    public function recentInstitutionEventCards(string $institutionId, int $limit): array
    {
        $query = $this->institutionEventCardQuery($institutionId);
        $upcoming = (clone $query)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        $events = $upcoming->isNotEmpty()
            ? $upcoming
            : $query
                ->orderByDesc('starts_at')
                ->orderBy('title')
                ->limit($limit)
                ->get();

        return $events
            ->map(fn (Event $event): InstitutionDashboardEventCard => $this->institutionDashboardCard($event))
            ->values()
            ->all();
    }

    /** @return list<InstitutionDashboardEventCard> */
    public function pendingInstitutionEventCards(
        string $institutionId,
        string $search,
        string $status,
        string $visibility,
        int $limit,
    ): array {
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
            ->get()
            ->map(fn (Event $event): InstitutionDashboardEventCard => $this->institutionDashboardCard($event))
            ->values()
            ->all();
    }

    public function institutionRegistrationCount(string $institutionId): int
    {
        return (int) Event::query()
            ->where('institution_id', $institutionId)
            ->withCount('registrations')
            ->get()
            ->sum(fn (Event $event): int => (int) ($event->registrations_count ?? 0));
    }

    /** @return array{total: int, upcoming: int, needs_attention: int} */
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
    ): PersonDashboardEventPage {
        $eventsTable = $this->eventTable();
        $query = $this->personDashboardEventQuery($personId)
            ->with('institution:id,name')
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

        $paginator = $query
            ->orderByRaw("CASE WHEN {$eventsTable}.status IN ('draft', 'pending', 'needs_changes') THEN 0 ELSE 1 END")
            ->orderByDesc("{$eventsTable}.starts_at")
            ->orderByDesc("{$eventsTable}.updated_at")
            ->paginate(
                perPage: $perPage,
                pageName: 'person_events_page',
                page: $page,
            );

        $actor = auth()->user();
        $items = [];

        foreach ($paginator->items() as $event) {
            $startsAt = $event->getAttribute('starts_at');
            $scheduleText = __('TBC');

            if ($startsAt instanceof CarbonInterface) {
                $scheduleText = UserDateTimeFormatter::translatedFormat($startsAt, 'd M Y').', '.(
                    $event->isPrayerRelative()
                        ? (string) $event->timing_display
                        : UserDateTimeFormatter::translatedFormat($startsAt, 'h:i A')
                );
            }

            $publiclyReachable = $event->isPubliclyReachable();

            $items[] = new PersonDashboardEvent(
                id: (string) $event->getKey(),
                title: (string) $event->title,
                statusLabel: $this->personDashboardStatusLabel($event->status),
                scheduleText: $scheduleText,
                institutionName: $event->institution?->name,
                registrationCount: (int) ($event->dashboard_registrations_count ?? 0),
                publiclyReachable: $publiclyReachable,
                publicUrl: $publiclyReachable ? route('events.show', $event) : null,
                canEdit: $actor instanceof User && $actor->can('update', $event),
            );
        }

        return new PersonDashboardEventPage(
            items: $items,
            total: $paginator->total(),
            perPage: $paginator->perPage(),
            currentPage: $paginator->currentPage(),
        );
    }

    /**
     * @param  list<string>  $referenceIds
     * @param  array<int|string, mixed>  $venueEagerLoads
     */
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
                                        $this->publicReferenceConstraint->apply($references)
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
                    $this->publicReferenceConstraint->apply($references->getQuery());
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
        $endInstant = $this->eventScheduleSql->endInstantSql((new Event)->getTable());

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

    /**
     * @param  list<string>  $institutionIds
     * @return array<string, array{total: int, public: int, upcoming: int, draft: int, pending: int}>
     */
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
     * @return array<string, array{next_event_id: ?string, next_event_slug: ?string, next_event_title: ?string, next_event_starts_at: ?string, upcoming_events_count: int}>
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
                'events.slug as event_slug',
                'events.title as event_title',
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
            $byReference[$referenceId]['event_meta'][$eventId] = [
                'slug' => (string) $row->event_slug,
                'title' => (string) $row->event_title,
            ];
            $byReference[$referenceId]['occurrences'][$occurrenceId] = true;
        }

        $facts = [];

        foreach ($referenceFamilies as $rootId => $ids) {
            $eventStarts = [];
            $eventMeta = [];
            $occurrences = [];

            foreach ($ids as $referenceId) {
                foreach ($byReference[$referenceId]['event_starts'] ?? [] as $eventId => $startsAt) {
                    $eventStarts[$eventId] = isset($eventStarts[$eventId]) ? min($eventStarts[$eventId], $startsAt) : $startsAt;
                }

                foreach ($byReference[$referenceId]['event_meta'] ?? [] as $eventId => $meta) {
                    $eventMeta[$eventId] = $meta;
                }

                foreach (array_keys($byReference[$referenceId]['occurrences'] ?? []) as $occurrenceId) {
                    $occurrences[$occurrenceId] = true;
                }
            }

            $nextEventId = null;
            $nextEventSlug = null;
            $nextEventTitle = null;
            $nextEventStartsAt = null;

            foreach ($eventStarts as $eventId => $startsAt) {
                if ($nextEventStartsAt === null || $startsAt < $nextEventStartsAt
                    || ($startsAt === $nextEventStartsAt && $nextEventId !== null && $eventId < $nextEventId)) {
                    $nextEventStartsAt = $startsAt;
                    $nextEventId = $eventId;
                    $nextEventSlug = $eventMeta[$eventId]['slug'] ?? null;
                    $nextEventTitle = $eventMeta[$eventId]['title'] ?? null;
                }
            }

            $facts[(string) $rootId] = [
                'next_event_id' => $nextEventId,
                'next_event_slug' => $nextEventSlug,
                'next_event_title' => $nextEventTitle,
                'next_event_starts_at' => $nextEventStartsAt,
                'upcoming_events_count' => count($occurrences),
            ];
        }

        return $facts;
    }

    /**
     * @param  list<string>  $personIds
     * @return array<string, array{
     *     events_count: int,
     *     next_event_slug: ?string,
     *     next_event_title: ?string,
     *     next_event_starts_at: ?string
     * }>
     */
    public function personDirectoryFacts(array $personIds): array
    {
        $personIds = $this->uniqueIds($personIds);

        if ($personIds === []) {
            return [];
        }

        $eventsTable = (new Event)->getTable();
        $eventInvolvementsTable = (new EventKeyPerson)->getTable();
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');
        $personMorphType = $this->personLookup->personMorphType();
        $now = now();

        $counts = DB::table("{$eventInvolvementsTable} as event_involvements")
            ->join("{$eventsTable} as events", 'events.id', '=', 'event_involvements.event_id')
            ->join("{$occurrencesTable} as occurrences", 'occurrences.event_id', '=', 'events.id')
            ->where('event_involvements.involveable_type', $personMorphType)
            ->whereIn('event_involvements.involveable_id', $personIds)
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereNotNull('events.published_at')
            ->whereIn('occurrences.status', $this->stringValues(Event::PUBLIC_SCHEDULE_STATUSES))
            ->whereIn('occurrences.visibility', $this->stringValues(Event::PUBLIC_SCHEDULE_VISIBILITIES))
            ->whereNotNull('occurrences.starts_at')
            ->where('occurrences.starts_at', '>=', $now)
            ->selectRaw('event_involvements.involveable_id, count(distinct occurrences.id) as events_count')
            ->groupBy('event_involvements.involveable_id')
            ->pluck('events_count', 'event_involvements.involveable_id');

        $rankedNextEvents = DB::table("{$eventInvolvementsTable} as event_involvements")
            ->join("{$eventsTable} as events", 'events.id', '=', 'event_involvements.event_id')
            ->join("{$occurrencesTable} as occurrences", 'occurrences.event_id', '=', 'events.id')
            ->where('event_involvements.involveable_type', $personMorphType)
            ->whereIn('event_involvements.involveable_id', $personIds)
            ->where('event_involvements.role_code', EventKeyPersonRole::Speaker->value)
            ->whereNotNull('occurrences.starts_at')
            ->where('occurrences.starts_at', '>=', $now)
            ->whereIn('occurrences.status', $this->stringValues(Event::PUBLIC_SCHEDULE_STATUSES))
            ->whereIn('occurrences.visibility', $this->stringValues(Event::PUBLIC_SCHEDULE_VISIBILITIES))
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereNotNull('events.published_at')
            ->select([
                'event_involvements.involveable_id as person_id',
                'events.slug as event_slug',
                'events.title as event_title',
                'occurrences.starts_at as event_starts_at',
            ])
            ->selectRaw('row_number() over (partition by event_involvements.involveable_id order by occurrences.starts_at asc, events.id asc) as rn');

        $nextEvents = DB::query()
            ->fromSub($rankedNextEvents, 'ranked_next_events')
            ->where('rn', 1)
            ->get()
            ->keyBy('person_id');

        $facts = [];

        foreach ($personIds as $personId) {
            $nextEvent = $nextEvents->get($personId);

            $facts[$personId] = [
                'events_count' => (int) ($counts->get($personId) ?? 0),
                'next_event_slug' => is_object($nextEvent) ? (string) $nextEvent->event_slug : null,
                'next_event_title' => is_object($nextEvent) ? (string) $nextEvent->event_title : null,
                'next_event_starts_at' => is_object($nextEvent) && filled($nextEvent->event_starts_at)
                    ? (string) $nextEvent->event_starts_at
                    : null,
            ];
        }

        return $facts;
    }

    /**
     * @return list<string>
     */
    public function upcomingSpeakerPersonIds(): array
    {
        $eventsTable = (new Event)->getTable();
        $eventInvolvementsTable = (new EventKeyPerson)->getTable();
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');

        return DB::table("{$eventInvolvementsTable} as event_involvements")
            ->join("{$eventsTable} as events", 'events.id', '=', 'event_involvements.event_id')
            ->join("{$occurrencesTable} as occurrences", 'occurrences.event_id', '=', 'events.id')
            ->where('event_involvements.involveable_type', $this->personLookup->personMorphType())
            ->where('event_involvements.role_code', EventKeyPersonRole::Speaker->value)
            ->whereIn('events.status', $this->stringValues(Event::PUBLIC_STATUSES))
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereNotNull('events.published_at')
            ->whereIn('occurrences.status', $this->stringValues(Event::PUBLIC_SCHEDULE_STATUSES))
            ->whereIn('occurrences.visibility', $this->stringValues(Event::PUBLIC_SCHEDULE_VISIBILITIES))
            ->whereNotNull('occurrences.starts_at')
            ->where('occurrences.starts_at', '>=', now())
            ->distinct()
            ->pluck('event_involvements.involveable_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $venueIds
     * @return array<string, int>
     */
    public function venueEventCounts(array $venueIds): array
    {
        $venueIds = $this->uniqueIds($venueIds);

        if ($venueIds === []) {
            return [];
        }

        $eventsTable = (new Event)->getTable();
        $occurrencesTable = (string) config('events.database.tables.event_occurrences', 'event_occurrences');

        $rows = DB::table($eventsTable)
            ->whereIn("{$eventsTable}.default_venue_id", $venueIds)
            ->whereIn("{$eventsTable}.status", $this->stringValues(Event::PUBLIC_STATUSES))
            ->where("{$eventsTable}.visibility", EventVisibility::Public->value)
            ->whereNotNull("{$eventsTable}.published_at")
            ->whereExists(static function (QueryBuilder $occurrenceQuery) use ($eventsTable, $occurrencesTable): void {
                $occurrenceQuery
                    ->selectRaw('1')
                    ->from($occurrencesTable)
                    ->whereColumn("{$occurrencesTable}.event_id", "{$eventsTable}.id");
            })
            ->groupBy("{$eventsTable}.default_venue_id")
            ->selectRaw("{$eventsTable}.default_venue_id as venue_id, count(*) as aggregate")
            ->get();

        $counts = array_fill_keys($venueIds, 0);

        foreach ($rows as $row) {
            $counts[(string) $row->venue_id] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $personIds
     * @param  list<string>  $institutionIds
     * @param  list<string>  $referenceIds
     */
    private function homepageEventQuery(array $personIds, array $institutionIds, array $referenceIds): EventBuilder
    {
        $occurrenceTable = (new EventOccurrence)->getTable();

        $query = $this->filterQueryBuilder->buildPublicEventQuery(null, ['time_scope' => 'all']);

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
        $baseQuery = $this->filterQueryBuilder->buildPublicEventQuery(null, ['time_scope' => 'all']);
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
                $this->publicReferenceConstraint->apply($query)->whereIn('references.id', $referenceIds);
            });
        }
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

    private function plannerCard(Event $event): PlannerEventCard
    {
        $start = $event->starts_at;

        return new PlannerEventCard(
            id: (string) $event->getKey(),
            title: (string) $event->title,
            status: (string) $event->status,
            startsAt: $start instanceof CarbonInterface ? CarbonImmutable::instance($start) : null,
            url: route('events.show', $event),
            imageUrl: (string) $event->card_image_url,
            locationName: $event->venue->name ?? $event->institution?->name,
        );
    }

    private function institutionDashboardCard(Event $event): InstitutionDashboardEventCard
    {
        $start = $event->starts_at;
        $end = $event->ends_at;

        $dateDay = '--';
        $dateMonth = (string) __('TBC');
        $dateWeekday = '';
        $timeLabel = (string) __('Time to be confirmed');

        if ($start instanceof CarbonInterface) {
            $dateDay = UserDateTimeFormatter::format($start, 'd');
            $dateMonth = UserDateTimeFormatter::translatedFormat($start, 'M');
            $dateWeekday = UserDateTimeFormatter::translatedFormat($start, 'D');
            $timeLabel = $event->isPrayerRelative()
                ? (string) $event->timing_display
                : UserDateTimeFormatter::format($start, 'h:i A');

            if (! $event->isPrayerRelative() && $end instanceof CarbonInterface) {
                $timeLabel .= ' - '.UserDateTimeFormatter::format($end, 'h:i A');
            }
        }

        $primaryLocation = $event->primaryLocation;
        $primarySpace = $primaryLocation?->venueSpace;

        $location = $this->spaceLocation->name(
            (string) ($primaryLocation->space_name_snapshot ?? ''),
            $primarySpace instanceof VenueSpace ? $primarySpace->name : null,
        ) ?? $event->venue->name
            ?? $event->institution->display_name
            ?? (string) __('Location to be confirmed');

        $actor = auth()->user();
        $canEdit = $actor instanceof User && $actor->can('update', $event);

        return new InstitutionDashboardEventCard(
            id: (string) $event->getKey(),
            title: (string) $event->title,
            status: (string) $event->status,
            publicUrl: route('events.show', $event),
            cardImageUrl: (string) $event->card_image_url,
            dateDay: $dateDay,
            dateMonth: $dateMonth,
            dateWeekday: $dateWeekday,
            timeLabel: $timeLabel,
            locationLabel: (string) $location,
            canEdit: $canEdit,
        );
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
            ]);
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

    private function personDashboardStatusLabel(mixed $status): string
    {
        if ($status instanceof \Filament\Support\Contracts\HasLabel) {
            return $status->getLabel();
        }

        if ($status instanceof BackedEnum) {
            $status = $status->value;
        }

        if (! is_scalar($status)) {
            return '';
        }

        $value = (string) $status;
        $translated = __($value);

        return $translated !== $value
            ? $translated
            : str($value)->replace('_', ' ')->headline()->toString();
    }

    private function personDashboardEventQuery(string $personId): EventBuilder
    {
        return Event::query()->whereHas('persons', function (Builder $personQuery) use ($personId): void {
            $personQuery->whereKey($personId);
        });
    }

    /**
     * @param  Builder<Event>  $query
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function eventCards(Builder $query, int $perPage): array
    {
        /** @var EloquentCollection<int, Event> $limitedEvents */
        $limitedEvents = (clone $query)
            ->select('events.*')
            ->addSelect(DB::raw('COUNT(*) OVER () AS __total_count'))
            ->take($perPage + 1)
            ->get();

        $visibleEvents = $limitedEvents
            ->take($perPage)
            ->values();

        $visibleEvents->loadMissing('displayOccurrence.media');

        return [
            'items' => $visibleEvents
                ->map(fn (Event $event): array => $this->eventListPayloads->forEvent($event)->toArray())
                ->all(),
            'total' => (int) ($limitedEvents->first()?->getAttribute('__total_count') ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participationData(EventKeyPerson $keyPerson): array
    {
        return [
            'id' => $keyPerson->id,
            'role' => (string) $keyPerson->role_code,
            'role_label' => $this->keyPersonRoleLabel($keyPerson->role_code),
            'display_name' => $keyPerson->display_name,
            'event' => $keyPerson->event instanceof Event
                ? $this->eventListPayloads->forEvent($keyPerson->event)->toArray()
                : null,
        ];
    }

    private function keyPersonRoleLabel(mixed $role): string
    {
        if ($role instanceof EventKeyPersonRole) {
            return $role->getLabel();
        }

        if ($role instanceof BackedEnum && is_string($role->value)) {
            return EventKeyPersonRole::tryFrom($role->value)?->getLabel() ?? Str::headline($role->value);
        }

        if (is_string($role) && $role !== '') {
            return EventKeyPersonRole::tryFrom($role)?->getLabel() ?? Str::headline($role);
        }

        return '';
    }

    private function directionSql(bool $past): string
    {
        return $this->eventScheduleSql->endInstantSql((new Event)->getTable()).($past ? ' < ?' : ' >= ?');
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
