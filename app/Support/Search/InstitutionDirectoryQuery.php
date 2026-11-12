<?php

namespace App\Support\Search;

use App\Models\Event;
use App\Models\Institution;
use App\Shared\Events\Enums\EventVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class InstitutionDirectoryQuery
{
    public function __construct(private InstitutionSearchService $searchService) {}

    /**
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Institution>
     */
    public function paginate(
        ?string $search,
        array $locationIds,
        bool $followedOnly,
        array $followingInstitutionIds,
        int $page,
        array $paginationMeta,
        int $perPage = 12,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $normalizedSearch = $this->searchService->normalizedSearch($search ?? '');

        if ($normalizedSearch === null) {
            $paginator = $this->baseInstitutionsQuery($locationIds, $followedOnly, $followingInstitutionIds)
                ->publicDirectoryOrder()
                ->paginate($perPage, page: $page)
                ->withPath($paginationMeta['path'])
                ->appends($paginationMeta['query']);
        } else {
            $directMatches = $this->paginateDirectSearch(
                $normalizedSearch,
                $locationIds,
                $followedOnly,
                $followingInstitutionIds,
                $page,
                $paginationMeta,
                $perPage,
            );

            $paginator = $directMatches->total() > 0 || mb_strlen($normalizedSearch) < 3
                ? $directMatches
                : $this->fuzzySearch(
                    $normalizedSearch,
                    $locationIds,
                    $followedOnly,
                    $followingInstitutionIds,
                    $page,
                    $paginationMeta,
                    $perPage,
                );
        }

        $this->attachNextPublicEvents($paginator->items());

        return $paginator;
    }

    /**
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Institution>
     */
    private function paginateDirectSearch(
        string $search,
        array $locationIds,
        bool $followedOnly,
        array $followingInstitutionIds,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $query = $this->baseInstitutionsQuery($locationIds, $followedOnly, $followingInstitutionIds);
        $query = $this->searchService->applyPublicSearch($query, $search);
        $query->publicDirectoryOrder();

        // Typesense returns a bounded candidate set (typesenseResultLimit)
        // before the location/followed scope is applied; the DB/collection
        // path has no such cap. Fully location-aware Typesense search would
        // require indexing location fields and adding filter_by constraints.
        $institutions = (clone $query)
            ->selectRaw('count(*) over () as matching_total')
            ->forPage($page, $perPage)
            ->get();

        $firstInstitution = $institutions->first();
        $total = $firstInstitution instanceof Institution
            ? (int) $firstInstitution->getAttribute('matching_total')
            : 0;

        // A page beyond the end has no row from which to read the window
        // total. This is rare and keeps pagination totals correct without
        // adding a count query to the normal request path.
        if ($institutions->isEmpty() && $page > 1) {
            $total = (clone $query)->reorder()->count();
        }

        $institutions->each(static fn (Institution $institution): Institution => $institution->makeHidden('matching_total'));

        return new LengthAwarePaginator($institutions, $total, $perPage, $page, $paginationMeta);
    }

    /**
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Institution>
     */
    private function fuzzySearch(
        string $search,
        array $locationIds,
        bool $followedOnly,
        array $followingInstitutionIds,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $orderedIds = $this->filterSearchIdsToCurrentScope(
            $this->searchService->publicFuzzySearchIds($search),
            $locationIds,
            $followedOnly,
            $followingInstitutionIds,
        );

        if ($orderedIds === []) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        }

        return $this->orderedIdPaginator(
            $orderedIds,
            $locationIds,
            $followedOnly,
            $followingInstitutionIds,
            $page,
            $paginationMeta,
            $perPage,
        );
    }

    /**
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @return Builder<Institution>
     */
    private function baseInstitutionsQuery(array $locationIds, bool $followedOnly, array $followingInstitutionIds): Builder
    {
        $query = $this->scopedInstitutionsQuery($locationIds, $followedOnly, $followingInstitutionIds);

        return $query
            ->select('institutions.*')
            ->selectSub($this->upcomingOccurrenceCountSubquery(), 'events_count')
            ->selectSub($this->nextPublicEventQuery()->select('events.id'), 'next_event_id')
            ->with([
                'addresses.state',
                'addresses.city',
                'addresses.areaAssignments.area',
                'media',
            ]);
    }

    /**
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @return Builder<Institution>
     */
    private function scopedInstitutionsQuery(array $locationIds, bool $followedOnly, array $followingInstitutionIds): Builder
    {
        return $this->applyFollowedOnlyFilter(
            $this->applyLocationScope(
                Institution::query()->whereIn('status', ['verified', 'pending']),
                $locationIds,
            ),
            $followedOnly,
            $followingInstitutionIds,
        );
    }

    /**
     * @param  Builder<Institution>  $query
     * @param  list<string>  $followingInstitutionIds
     * @return Builder<Institution>
     */
    private function applyFollowedOnlyFilter(Builder $query, bool $followedOnly, array $followingInstitutionIds): Builder
    {
        if (! $followedOnly) {
            return $query;
        }

        return $query->whereIn('institutions.id', $followingInstitutionIds);
    }

    /**
     * @param  Builder<Institution>  $query
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @return Builder<Institution>
     */
    private function applyLocationScope(Builder $query, array $locationIds): Builder
    {
        $countryId = $locationIds['country_id'] ?? null;
        $stateId = $locationIds['state_id'] ?? null;
        $cityId = $locationIds['city_id'] ?? null;
        $localityId = $locationIds['locality_id'] ?? null;
        $areaIds = $locationIds['area_ids'] ?? [];

        // district_id/subdivision_id are legacy aliases folded into area_ids
        // by LocationSlugResolver::idsForSlugs(); only area_ids is applied.
        if ($countryId === null && $stateId === null && $cityId === null && $localityId === null && ! array_filter($areaIds)) {
            return $query;
        }

        return $query->whereHas('addresses', function (Builder $addressQuery) use ($countryId, $stateId, $cityId, $localityId, $areaIds): void {
            if ($countryId !== null) {
                $addressQuery->where('country_id', $countryId);
            }

            if ($stateId !== null) {
                $addressQuery->where('state_id', $stateId);
            }

            if ($cityId !== null) {
                $addressQuery->where('city_id', $cityId);
            }

            if ($localityId !== null) {
                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', 'postal_locality')->where('address_area_id', $localityId));
            }

            foreach ($areaIds as $role => $areaId) {
                if ($areaId === null) {
                    continue;
                }

                $addressQuery->whereHas('areaAssignments', fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', $role)->where('address_area_id', $areaId));
            }
        });
    }

    /**
     * Count upcoming majlis as occurrences: sessions live inside an
     * occurrence and must not inflate the directory card. This matches the
     * institution page hero and card list.
     */
    private function upcomingOccurrenceCountSubquery(): QueryBuilder
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

        return DB::table("{$occurrencesTable} as public_occurrences")
            ->join('events', 'events.id', '=', 'public_occurrences.event_id')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->whereIn('public_occurrences.status', Event::PUBLIC_SCHEDULE_STATUSES)
            ->whereIn('public_occurrences.visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES)
            ->whereNotNull('public_occurrences.starts_at')
            ->where('public_occurrences.starts_at', '>=', now())
            ->selectRaw('count(*)');
    }

    /**
     * @return Builder<Event>
     */
    private function nextPublicEventQuery(): Builder
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

        return Event::query()
            ->join("{$occurrencesTable} as next_event_occurrences", 'next_event_occurrences.event_id', '=', 'events.id')
            ->whereColumn('events.institution_id', 'institutions.id')
            ->where('next_event_occurrences.starts_at', '>=', now())
            ->whereNotNull('events.published_at')
            ->whereIn('events.status', Event::PUBLIC_STATUSES)
            ->where('events.visibility', EventVisibility::Public->value)
            ->orderBy('next_event_occurrences.starts_at')
            ->orderBy('events.id')
            ->limit(1);
    }

    /**
     * Hydrate the next-event card fields for one page of institutions in a
     * single batched query. The listing only needs these three columns for
     * the visible page, so repeating the same correlated LIMIT 1 subquery
     * three times per row only triples that work — and each repetition
     * evaluates its own "now", which can mix columns from different events
     * at the boundary. Selecting the winning id per row and resolving its
     * columns once keeps every card internally consistent.
     *
     * @param  array<int, Institution>  $institutions
     */
    private function attachNextPublicEvents(array $institutions): void
    {
        if ($institutions === []) {
            return;
        }

        $nextEventIds = collect($institutions)
            ->map(static fn (Institution $institution): mixed => $institution->getAttribute('next_event_id'))
            ->filter()
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        /** @var EloquentCollection<string, Event> $details */
        $details = $nextEventIds === []
            ? new EloquentCollection
            : $this->nextPublicEventDetails($nextEventIds)->keyBy(static fn (Event $event): string => (string) $event->getKey());

        foreach ($institutions as $institution) {
            $detail = $details->get((string) $institution->getAttribute('next_event_id'));

            $institution->setAttribute('next_event_slug', $detail?->getAttribute('slug'));
            $institution->setAttribute('next_event_title', $detail?->getAttribute('title'));
            $institution->setAttribute('next_event_starts_at', $detail?->getAttribute('next_event_starts_at'));
            $institution->offsetUnset('next_event_id');
        }
    }

    /**
     * Resolve the card columns for already-selected next events. The id
     * already identifies the earliest future (event, occurrence) pair per
     * institution, so the event's minimum future occurrence start is exactly
     * that pair's start — no tie-break needed for the timestamp itself.
     *
     * @param  list<string>  $eventIds
     * @return EloquentCollection<int, Event>
     */
    private function nextPublicEventDetails(array $eventIds): EloquentCollection
    {
        $occurrencesTable = config('events.database.tables.event_occurrences', 'event_occurrences');

        return Event::query()
            ->select('events.id', 'events.slug', 'events.title')
            ->selectRaw('min("'.$occurrencesTable.'"."starts_at") as next_event_starts_at')
            ->join("{$occurrencesTable}", "{$occurrencesTable}.event_id", '=', 'events.id')
            ->whereIn('events.id', $eventIds)
            ->where("{$occurrencesTable}.starts_at", '>=', now())
            ->groupBy('events.id', 'events.slug', 'events.title')
            ->get();
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @return list<string>
     */
    private function filterSearchIdsToCurrentScope(
        array $orderedIds,
        array $locationIds,
        bool $followedOnly,
        array $followingInstitutionIds,
        bool $useDirectoryOrder = false,
    ): array {
        if ($orderedIds === []) {
            return [];
        }

        $scopedQuery = $this->scopedInstitutionsQuery($locationIds, $followedOnly, $followingInstitutionIds)
            ->whereIn('institutions.id', $orderedIds);

        if ($useDirectoryOrder) {
            $scopedQuery->publicDirectoryOrder();
        }

        $scopedIds = $scopedQuery
            ->pluck('institutions.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($useDirectoryOrder) {
            return $scopedIds;
        }

        return collect($scopedIds)
            ->sortBy(static function (string $id) use ($orderedIds): int {
                $position = array_search($id, $orderedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Institution>
     */
    private function orderedIdPaginator(
        array $orderedIds,
        array $locationIds,
        bool $followedOnly,
        array $followingInstitutionIds,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $paginatedIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $page, $paginationMeta);
        }

        return $this->hydrateOrderedIds(
            $paginatedIds,
            count($orderedIds),
            $page,
            $paginationMeta,
            $locationIds,
            $followedOnly,
            $followingInstitutionIds,
            $perPage,
        );
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, district_id?: ?string, subdivision_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Institution>
     */
    private function hydrateOrderedIds(
        array $orderedIds,
        int $total,
        int $currentPage,
        array $paginationMeta,
        array $locationIds,
        bool $followedOnly,
        array $followingInstitutionIds,
        int $perPage,
    ): LengthAwarePaginator {
        $institutions = $this->baseInstitutionsQuery($locationIds, $followedOnly, $followingInstitutionIds)
            ->whereIn('institutions.id', $orderedIds)
            ->get()
            ->sortBy(static function (Institution $institution) use ($orderedIds): int {
                $position = array_search((string) $institution->id, $orderedIds, true);

                return is_int($position) ? $position : PHP_INT_MAX;
            })
            ->values();

        return new LengthAwarePaginator($institutions, $total, $perPage, $currentPage, $paginationMeta);
    }

    /**
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Institution>
     */
    private function emptyPaginator(int $page, array $paginationMeta, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect(), 0, $perPage, max(1, $page), $paginationMeta);
    }
}
