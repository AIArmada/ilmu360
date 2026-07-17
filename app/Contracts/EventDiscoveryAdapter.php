<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\EventDiscoveryCriteria;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventDiscoveryAdapter
{
    /** @return LengthAwarePaginator<int, Event> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Event> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, Event> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator;
}
