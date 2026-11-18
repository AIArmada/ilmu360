<?php

declare(strict_types=1);

namespace App\Platform\Search;

use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EventDiscoveryReader
{
    /** @return LengthAwarePaginator<int, mixed> */
    public function search(EventDiscoveryCriteria $criteria): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, mixed> */
    public function nearby(EventDiscoveryCriteria $criteria): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, mixed> */
    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): LengthAwarePaginator;
}
