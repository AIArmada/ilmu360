<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventDiscoveryAdapter;
use App\Data\EventDiscoveryCriteria;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class TypesenseEventDiscovery implements EventDiscoveryAdapter
{
    public function __construct(private EventSearchService $searchService) {}

    /** @return LengthAwarePaginator<int, Event> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchService->searchWithTypesenseCriteria($criteria);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchService->searchNearbyWithTypesenseCriteria($criteria);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchService->searchNearbyWithTypesenseQueryCriteria($criteria);
    }
}
