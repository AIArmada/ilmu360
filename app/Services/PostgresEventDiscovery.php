<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventDiscoveryAdapter;
use App\Data\EventDiscoveryCriteria;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class PostgresEventDiscovery implements EventDiscoveryAdapter
{
    public function __construct(private EventSearchService $searchService) {}

    /** @return LengthAwarePaginator<int, Event> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchService->searchWithDatabaseCriteria($criteria);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchService->searchNearbyWithDatabaseCriteria($criteria);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return $this->searchService->searchNearbyWithDatabaseQueryCriteria($criteria);
    }
}
