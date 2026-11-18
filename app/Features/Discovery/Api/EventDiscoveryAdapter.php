<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Events\Models\Event;
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
