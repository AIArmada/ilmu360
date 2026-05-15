<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
use App\Support\Cache\PublicDirectoryCacheVersion;
use App\Support\Cache\PublicListingsCache;
use App\Support\Location\FederalTerritoryLocation;

class GeographyObserver
{
    public function __construct(
        protected PublicDirectoryCacheVersion $publicDirectoryCacheVersion,
        protected PublicListingsCache $publicListingsCache
    ) {}

    public function saved(Country|State|District|Subdistrict $geography): void
    {
        FederalTerritoryLocation::flushStateIdCache();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpAll();
    }

    public function deleted(Country|State|District|Subdistrict $geography): void
    {
        FederalTerritoryLocation::flushStateIdCache();
        $this->publicListingsCache->bustMajlisListing();
        $this->publicDirectoryCacheVersion->bumpAll();
    }
}
