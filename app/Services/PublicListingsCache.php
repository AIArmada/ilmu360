<?php

declare(strict_types=1);

namespace App\Services;

use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\Institutions\Api\InstitutionHomepageStats;
use App\Features\References\Api\ReferenceHomepageStats;
use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\Api\EventSearchCache;

final class PublicListingsCache
{
    public function __construct(
        private readonly EventSearchCache $eventSearchCache,
        private readonly EventHomepageStats $eventHomepageStats,
        private readonly PersonHomepageStats $personStats,
        private readonly InstitutionHomepageStats $institutionStats,
        private readonly ReferenceHomepageStats $referenceStats,
    ) {}

    public function bustHomepageStats(): void
    {
        $this->eventHomepageStats->forgetCachedCount();
        $this->personStats->forgetCachedCount();
        $this->institutionStats->forgetCachedCount();
        $this->referenceStats->forgetCachedCount();
    }

    public function bustMajlisListing(): void
    {
        $this->eventSearchCache->forgetDefaultSearch();
    }
}
