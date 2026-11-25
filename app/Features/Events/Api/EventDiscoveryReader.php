<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Features\Discovery\Api\Data\EventDiscoveryCriteria;
use App\Features\Discovery\Api\Data\EventSearchHitIds;
use App\Features\Events\Api\Data\EventDiscoveryPage;

interface EventDiscoveryReader
{
    public function search(EventDiscoveryCriteria $criteria): EventDiscoveryPage;

    public function searchIds(EventDiscoveryCriteria $criteria): EventSearchHitIds;

    public function nearby(EventDiscoveryCriteria $criteria): EventDiscoveryPage;

    public function nearbyIds(EventDiscoveryCriteria $criteria): EventSearchHitIds;

    public function nearbyWithQuery(EventDiscoveryCriteria $criteria): EventDiscoveryPage;

    public function nearbyWithQueryIds(EventDiscoveryCriteria $criteria): EventSearchHitIds;
}
