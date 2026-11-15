<?php

declare(strict_types=1);

namespace App\Services;

use App\Features\Directory\Api\PublicDirectoryStats;
use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\Api\EventSearchCache;

final class PublicListingsCache
{
    public function __construct(
        private readonly EventSearchCache $eventSearchCache,
        private readonly EventHomepageStats $eventHomepageStats,
        private readonly PublicDirectoryStats $publicDirectoryStats,
    ) {}

    public function bustHomepageStats(): void
    {
        $this->eventHomepageStats->forgetCachedCount();
        $this->publicDirectoryStats->forgetCachedCounts();
    }

    public function bustMajlisListing(): void
    {
        $this->eventSearchCache->forgetDefaultSearch();
    }
}
