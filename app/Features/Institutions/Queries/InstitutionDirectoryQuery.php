<?php

namespace App\Features\Institutions\Queries;

use App\Features\Events\Api\Orm\EventOrmPort;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Services\InstitutionSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class InstitutionDirectoryQuery
{
    public function __construct(
        private InstitutionSearchService $searchService,
        private EventOrmPort $eventReader,
    ) {}

    /**
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
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
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
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
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
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
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @param  list<string>  $followingInstitutionIds
     * @return Builder<Institution>
     */
    private function baseInstitutionsQuery(array $locationIds, bool $followedOnly, array $followingInstitutionIds): Builder
    {
        $query = $this->scopedInstitutionsQuery($locationIds, $followedOnly, $followingInstitutionIds);

        return $query
            ->select('institutions.*')
            ->selectSub($this->eventReader->institutionUpcomingCountSubquery(), 'events_count')
            ->selectSub($this->eventReader->institutionNextEventIdSubquery(), 'next_event_id')
            ->with([
                'addresses.state',
                'addresses.city',
                'addresses.areaAssignments.area',
                'media',
            ]);
    }

    /**
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
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
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
     * @return Builder<Institution>
     */
    private function applyLocationScope(Builder $query, array $locationIds): Builder
    {
        $countryId = $locationIds['country_id'] ?? null;
        $stateId = $locationIds['state_id'] ?? null;
        $cityId = $locationIds['city_id'] ?? null;
        $localityId = $locationIds['locality_id'] ?? null;
        $areaIds = $locationIds['area_ids'] ?? [];

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
     * Hydrate the next-event card fields and upcoming occurrence count for one
     * page of institutions through the Events owner's batched read.
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

        $details = $this->nextEventDetails($nextEventIds);

        foreach ($institutions as $institution) {
            $detail = $details->get((string) $institution->getAttribute('next_event_id'));

            $institution->setAttribute('next_event_slug', $detail?->getAttribute('slug'));
            $institution->setAttribute('next_event_title', $detail?->getAttribute('title'));
            $institution->setAttribute('next_event_starts_at', $detail?->getAttribute('next_event_starts_at'));
            $institution->offsetUnset('next_event_id');
        }
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
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
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
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
     * @param  array{country_id?: ?string, state_id?: ?string, city_id?: ?string, locality_id?: ?string, area_ids?: array<string, ?string>}  $locationIds
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

    /**
     * @param  list<string>  $eventIds
     * @return \Illuminate\Support\Collection<string, \App\Features\Events\Models\Event>
     */
    private function nextEventDetails(array $eventIds): \Illuminate\Support\Collection
    {
        if ($eventIds === []) {
            return collect();
        }

        $details = $this->eventReader->institutionNextEventDetails($eventIds);

        return $details->keyBy(static fn (\Illuminate\Database\Eloquent\Model $event): string => (string) $event->getKey());
    }
}
