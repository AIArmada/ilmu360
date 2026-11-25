<?php

declare(strict_types=1);

namespace App\Features\Discovery\Services;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventHomepageStats;
use App\Features\Events\Api\EventSearchCache;
use App\Features\Institutions\Api\InstitutionHomepageStats;
use App\Features\Persons\Api\PersonHomepageStats;
use App\Features\References\Api\ReferenceHomepageStats;

final readonly class PublicListingsCache implements PublicListingsCacheInvalidator
{
    public function __construct(
        private EventSearchCache $eventSearchCache,
        private EventHomepageStats $eventHomepageStats,
        private PersonHomepageStats $personStats,
        private InstitutionHomepageStats $institutionStats,
        private ReferenceHomepageStats $referenceStats,
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
        $this->eventSearchCache->bust();
    }
}
