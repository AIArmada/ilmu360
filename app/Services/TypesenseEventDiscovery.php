<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\EventDiscoveryCriteria;
use App\Models\Event;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class TypesenseEventDiscovery
{
    /**
     * @param  Closure(EventDiscoveryCriteria): LengthAwarePaginator<int, Event>  $search
     * @param  Closure(EventDiscoveryCriteria): LengthAwarePaginator<int, Event>  $nearby
     * @param  Closure(EventDiscoveryCriteria): LengthAwarePaginator<int, Event>  $nearbyWithQuery
     */
    public function __construct(
        private Closure $search,
        private Closure $nearby,
        private Closure $nearbyWithQuery,
    ) {}

    /** @return LengthAwarePaginator<int, Event> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return ($this->search)($criteria);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return ($this->nearby)($criteria);
    }

    /** @return LengthAwarePaginator<int, Event> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator
    {
        return ($this->nearbyWithQuery)($criteria);
    }
}
