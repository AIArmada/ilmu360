<?php

declare(strict_types=1);

namespace App\Features\Places\Queries;

use App\Features\Events\Api\EventScheduleReads;
use App\Features\Places\Models\Venue;
use App\Features\Places\Services\VenueSearchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class VenueDirectoryQuery
{
    public function __construct(
        private VenueSearchService $searchService,
        private EventScheduleReads $scheduleReads,
    ) {}

    /**
     * @param  array<string, string>  $areas
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Venue>
     */
    public function paginate(
        ?string $search,
        ?string $countryId,
        ?string $stateId,
        array $areas = [],
        ?string $venueType = null,
        int $page = 1,
        array $paginationMeta = ['path' => '/', 'query' => []],
        int $perPage = 12,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $normalizedSearch = $this->searchService->normalizedSearch($search ?? '');

        if ($normalizedSearch === null) {
            $query = $this->baseVenuesQuery($countryId, $stateId, $areas, $venueType)
                ->orderBy('venues.name');

            $paginator = $this->paginateWithWindowTotal($query, $page, $paginationMeta, $perPage);
            $this->attachEventCounts($paginator->items());

            return $paginator;
        }

        $directMatches = $this->paginateDirectSearch(
            $normalizedSearch,
            $countryId,
            $stateId,
            $areas,
            $venueType,
            $page,
            $paginationMeta,
            $perPage,
        );

        if ($directMatches->total() > 0 || mb_strlen($normalizedSearch) < 3) {
            $this->attachEventCounts($directMatches->items());

            return $directMatches;
        }

        $fuzzyMatches = $this->fuzzySearch(
            $normalizedSearch,
            $countryId,
            $stateId,
            $areas,
            $venueType,
            $page,
            $paginationMeta,
            $perPage,
        );

        $this->attachEventCounts($fuzzyMatches->items());

        return $fuzzyMatches;
    }

    /**
     * @param  array<string, string>  $areas
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Venue>
     */
    private function paginateDirectSearch(
        string $search,
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        if (! $this->hasScopedFilters($countryId, $stateId, $areas, $venueType)) {
            $orderedIds = $this->filterSearchIdsToCurrentScope(
                $this->searchService->publicSearchIds($search),
                $countryId,
                $stateId,
                $areas,
                $venueType,
                useDirectoryOrder: true,
            );

            if ($orderedIds === []) {
                return $this->emptyPaginator($page, $paginationMeta, $perPage);
            }

            return $this->orderedIdPaginator(
                $orderedIds,
                $countryId,
                $stateId,
                $areas,
                $venueType,
                $page,
                $paginationMeta,
                $perPage,
            );
        }

        $query = $this->baseVenuesQuery($countryId, $stateId, $areas, $venueType);
        $query = $this->searchService->applyPublicSearch($query, $search)
            ->orderBy('venues.name');

        return $this->paginateWithWindowTotal($query, $page, $paginationMeta, $perPage);
    }

    /**
     * @param  array<string, string>  $areas
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Venue>
     */
    private function fuzzySearch(
        string $search,
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $orderedIds = $this->filterSearchIdsToCurrentScope(
            $this->searchService->publicFuzzySearchIds($search),
            $countryId,
            $stateId,
            $areas,
            $venueType,
        );

        if ($orderedIds === []) {
            return $this->emptyPaginator($page, $paginationMeta, $perPage);
        }

        return $this->orderedIdPaginator(
            $orderedIds,
            $countryId,
            $stateId,
            $areas,
            $venueType,
            $page,
            $paginationMeta,
            $perPage,
        );
    }

    /**
     * @param  array<string, string>  $areas
     */
    private function hasScopedFilters(?string $countryId, ?string $stateId, array $areas, ?string $venueType): bool
    {
        return $countryId !== null
            || $stateId !== null
            || $areas !== []
            || ($venueType !== null && $venueType !== '');
    }

    /**
     * @param  array<string, string>  $areas
     * @return Builder<Venue>
     */
    private function baseVenuesQuery(
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
    ): Builder {
        return $this->scopedVenuesQuery($countryId, $stateId, $areas, $venueType)
            ->select('venues.*')
            ->with([
                'addresses.areaAssignments.area',
                'media' => static function (MorphMany $relation): void {
                    $relation->whereIn('collection_name', ['main', 'cover']);
                },
            ]);
    }

    /**
     * @param  array<string, string>  $areas
     * @return Builder<Venue>
     */
    private function scopedVenuesQuery(
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
    ): Builder {
        $query = Venue::query()
            ->active()
            ->where('status', 'verified');

        if ($venueType !== null && $venueType !== '') {
            $query->where('venue_type', $venueType);
        }

        return $this->applyLocationScope($query, $countryId, $stateId, $areas);
    }

    /**
     * @param  Builder<Venue>  $query
     * @param  array<string, string>  $areas
     * @return Builder<Venue>
     */
    private function applyLocationScope(
        Builder $query,
        ?string $countryId,
        ?string $stateId,
        array $areas,
    ): Builder {
        if ($countryId === null && $stateId === null && $areas === []) {
            return $query;
        }

        return $query->whereHas('addresses', static function (Builder $addressQuery) use ($countryId, $stateId, $areas): void {
            if ($countryId !== null) {
                $addressQuery->where('country_id', $countryId);
            }

            if ($stateId !== null) {
                $addressQuery->where('state_id', $stateId);
            }

            foreach ($areas as $role => $areaId) {
                $addressQuery->whereHas('areaAssignments', static fn (Builder $assignmentQuery) => $assignmentQuery
                    ->where('role', $role)
                    ->where('address_area_id', $areaId));
            }
        });
    }

    /**
     * @param  Builder<Venue>  $query
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Venue>
     */
    private function paginateWithWindowTotal(
        Builder $query,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $venues = (clone $query)
            ->selectRaw('count(*) over () as matching_total')
            ->forPage($page, $perPage)
            ->get();

        $firstVenue = $venues->first();
        $total = $firstVenue instanceof Venue
            ? (int) $firstVenue->getAttribute('matching_total')
            : 0;

        if ($venues->isEmpty() && $page > 1) {
            $total = (clone $query)->reorder()->count();
        }

        $venues->each(static fn (Venue $venue): Venue => $venue->makeHidden('matching_total'));

        return new LengthAwarePaginator($venues, $total, $perPage, $page, $paginationMeta)
            ->appends($paginationMeta['query']);
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  array<string, string>  $areas
     * @return list<string>
     */
    private function filterSearchIdsToCurrentScope(
        array $orderedIds,
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        bool $useDirectoryOrder = false,
    ): array {
        if ($orderedIds === []) {
            return [];
        }

        $scopedQuery = $this->scopedVenuesQuery($countryId, $stateId, $areas, $venueType)
            ->whereIn('venues.id', $orderedIds);

        if ($useDirectoryOrder) {
            $scopedQuery->orderBy('venues.name');
        }

        $scopedIds = $scopedQuery
            ->pluck('venues.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($useDirectoryOrder) {
            return $scopedIds;
        }

        $positions = array_flip($orderedIds);

        return collect($scopedIds)
            ->sortBy(static fn (string $id): int => $positions[$id] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $orderedIds
     * @param  array<string, string>  $areas
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Venue>
     */
    private function orderedIdPaginator(
        array $orderedIds,
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        int $page,
        array $paginationMeta,
        int $perPage,
    ): LengthAwarePaginator {
        $paginatedIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $page, $paginationMeta)
                ->appends($paginationMeta['query']);
        }

        $positions = array_flip($paginatedIds);

        $venues = $this->baseVenuesQuery($countryId, $stateId, $areas, $venueType)
            ->whereIn('venues.id', $paginatedIds)
            ->get()
            ->sortBy(static fn (Venue $venue): int => $positions[(string) $venue->id] ?? PHP_INT_MAX)
            ->values();

        return new LengthAwarePaginator($venues, count($orderedIds), $perPage, $page, $paginationMeta)
            ->appends($paginationMeta['query']);
    }

    /**
     * @param  array<int, Venue>  $venues
     */
    private function attachEventCounts(array $venues): void
    {
        if ($venues === []) {
            return;
        }

        $venueIds = array_values(array_map(
            static fn (Venue $venue): string => (string) $venue->getKey(),
            $venues,
        ));

        $counts = $this->scheduleReads->venueEventCounts($venueIds);

        foreach ($venues as $venue) {
            $venue->setAttribute('events_count', $counts[(string) $venue->getKey()] ?? 0);
        }
    }

    /**
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @return LengthAwarePaginator<int, Venue>
     */
    private function emptyPaginator(int $page, array $paginationMeta, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(collect(), 0, $perPage, max(1, $page), $paginationMeta)
            ->appends($paginationMeta['query']);
    }
}
