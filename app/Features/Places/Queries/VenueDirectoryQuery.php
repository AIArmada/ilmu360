<?php

declare(strict_types=1);

namespace App\Features\Places\Queries;

use App\Features\Events\Api\EventScheduleReads;
use App\Features\Places\Models\Venue;
use App\Features\Places\Services\VenueSearchService;
use App\Platform\Location\SpatialAddressQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class VenueDirectoryQuery
{
    public function __construct(
        private VenueSearchService $searchService,
        private EventScheduleReads $scheduleReads,
        private SpatialAddressQuery $spatialAddressQuery,
    ) {}

    /**
     * @param  array<string, string>  $areas
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @param  array<string, mixed>|string|null  $bounds
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
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        array|string|null $bounds = null,
        ?string $polygon = null,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $normalizedSearch = $this->searchService->normalizedSearch($search ?? '');
        $parsedBounds = $this->spatialAddressQuery->parseBoundingBox($bounds);
        $normalizedPolygon = $this->spatialAddressQuery->normalizePolygonWkt($polygon);

        if ($normalizedSearch === null) {
            $query = $this->baseVenuesQuery($countryId, $stateId, $areas, $venueType, $latitude, $longitude, $radiusKm, $parsedBounds, $normalizedPolygon)
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
            $latitude,
            $longitude,
            $radiusKm,
            $parsedBounds,
            $normalizedPolygon,
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
            $latitude,
            $longitude,
            $radiusKm,
            $parsedBounds,
            $normalizedPolygon,
        );

        $this->attachEventCounts($fuzzyMatches->items());

        return $fuzzyMatches;
    }

    /**
     * @param  array<string, string>  $areas
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
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
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?array $bounds = null,
        ?string $polygon = null,
    ): LengthAwarePaginator {
        if (! $this->hasScopedFilters($countryId, $stateId, $areas, $venueType, $latitude, $longitude, $bounds, $polygon)) {
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

        $query = $this->baseVenuesQuery($countryId, $stateId, $areas, $venueType, $latitude, $longitude, $radiusKm, $bounds, $polygon);
        $query = $this->searchService->applyPublicSearch($query, $search)
            ->orderBy('venues.name');

        return $this->paginateWithWindowTotal($query, $page, $paginationMeta, $perPage);
    }

    /**
     * @param  array<string, string>  $areas
     * @param  array{path: string, query: array<string, mixed>}  $paginationMeta
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
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
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?array $bounds = null,
        ?string $polygon = null,
    ): LengthAwarePaginator {
        $orderedIds = $this->filterSearchIdsToCurrentScope(
            $this->searchService->publicFuzzySearchIds($search),
            $countryId,
            $stateId,
            $areas,
            $venueType,
            false,
            $latitude,
            $longitude,
            $radiusKm,
            $bounds,
            $polygon,
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
            $latitude,
            $longitude,
            $radiusKm,
            $bounds,
            $polygon,
        );
    }

    /**
     * @param  array<string, string>  $areas
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
     */
    private function hasScopedFilters(
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        ?float $latitude = null,
        ?float $longitude = null,
        ?array $bounds = null,
        ?string $polygon = null,
    ): bool {
        return $countryId !== null
            || $stateId !== null
            || $areas !== []
            || ($venueType !== null && $venueType !== '')
            || ($latitude !== null && $longitude !== null)
            || $bounds !== null
            || $polygon !== null;
    }

    /**
     * @param  array<string, string>  $areas
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
     * @return Builder<Venue>
     */
    private function baseVenuesQuery(
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?array $bounds = null,
        ?string $polygon = null,
    ): Builder {
        return $this->scopedVenuesQuery(
            $countryId,
            $stateId,
            $areas,
            $venueType,
            $latitude,
            $longitude,
            $radiusKm,
            $bounds,
            $polygon,
            withDistanceProjection: true,
        )
            ->with([
                'addresses.areaAssignments.area',
                'media' => static function (MorphMany $relation): void {
                    $relation->whereIn('collection_name', ['main', 'cover']);
                },
            ]);
    }

    /**
     * @param  array<string, string>  $areas
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
     * @return Builder<Venue>
     */
    private function scopedVenuesQuery(
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?array $bounds = null,
        ?string $polygon = null,
        bool $withDistanceProjection = false,
    ): Builder {
        $query = Venue::query()
            ->active()
            ->where('status', 'verified');

        if ($venueType !== null && $venueType !== '') {
            $query->where('venue_type', $venueType);
        }

        $query = $this->applyLocationScope($query, $countryId, $stateId, $areas);

        return $this->applySpatialScope($query, $latitude, $longitude, $radiusKm, $bounds, $polygon, $withDistanceProjection);
    }

    /**
     * @param  Builder<Venue>  $query
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
     * @return Builder<Venue>
     */
    private function applySpatialScope(
        Builder $query,
        ?float $latitude,
        ?float $longitude,
        ?int $radiusKm,
        ?array $bounds,
        ?string $polygon,
        bool $withDistanceProjection,
    ): Builder {
        $morphType = (new Venue)->getMorphClass();

        if ($withDistanceProjection) {
            $query->select('venues.*');
        }

        if ($bounds !== null) {
            $this->spatialAddressQuery->applyPrimaryAddressWithinBounds(
                $query,
                'venues',
                'id',
                $morphType,
                $bounds['west'],
                $bounds['south'],
                $bounds['east'],
                $bounds['north'],
            );
        }

        if ($polygon !== null) {
            $this->spatialAddressQuery->applyPrimaryAddressWithinPolygon(
                $query,
                'venues',
                'id',
                $morphType,
                $polygon,
            );
        }

        if ($latitude !== null && $longitude !== null) {
            if ($radiusKm !== null) {
                $this->spatialAddressQuery->applyPrimaryAddressNearby(
                    $query,
                    'venues',
                    'id',
                    $morphType,
                    $latitude,
                    $longitude,
                    $radiusKm,
                    'venue_spatial',
                    $withDistanceProjection ? ['venues.*'] : ['venues.id'],
                );

                if (! $withDistanceProjection) {
                    $query->reorder();
                }
            } elseif ($withDistanceProjection) {
                $this->spatialAddressQuery->selectPrimaryAddressDistance(
                    $query,
                    'venues',
                    'id',
                    $morphType,
                    $latitude,
                    $longitude,
                    'venue_distance',
                    ['venues.*'],
                );
            }
        }

        return $query;
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
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
     * @return list<string>
     */
    private function filterSearchIdsToCurrentScope(
        array $orderedIds,
        ?string $countryId,
        ?string $stateId,
        array $areas,
        ?string $venueType,
        bool $useDirectoryOrder = false,
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?array $bounds = null,
        ?string $polygon = null,
    ): array {
        if ($orderedIds === []) {
            return [];
        }

        $scopedQuery = $this->scopedVenuesQuery($countryId, $stateId, $areas, $venueType, $latitude, $longitude, $radiusKm, $bounds, $polygon)
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
     * @param  array{west: float, south: float, east: float, north: float}|null  $bounds
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
        ?float $latitude = null,
        ?float $longitude = null,
        ?int $radiusKm = null,
        ?array $bounds = null,
        ?string $polygon = null,
    ): LengthAwarePaginator {
        $paginatedIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);

        if ($paginatedIds === []) {
            return new LengthAwarePaginator(collect(), count($orderedIds), $perPage, $page, $paginationMeta)
                ->appends($paginationMeta['query']);
        }

        $positions = array_flip($paginatedIds);

        $venues = $this->baseVenuesQuery($countryId, $stateId, $areas, $venueType, $latitude, $longitude, $radiusKm, $bounds, $polygon)
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
