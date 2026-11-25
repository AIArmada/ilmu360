<?php

declare(strict_types=1);

namespace App\Features\Discovery\Actions;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Features\Institutions\Api\InstitutionListingCacheVersion;
use App\Features\Persons\Api\PersonListingCacheVersion;
use App\Shared\Cache\SelectionCatalog;
use App\Shared\Contracts\AddressCatalogEffects;

final readonly class InvalidateAddressCatalogsAction implements AddressCatalogEffects
{
    public function __construct(
        private InstitutionListingCacheVersion $institutions,
        private PersonListingCacheVersion $persons,
        private PublicListingsCacheInvalidator $listings,
        private SelectionCatalog $catalogs,
        private EventSubmissionOptionsCache $eventSubmissionOptions,
    ) {}

    public function invalidateLocationCatalogs(): void
    {
        $this->institutions->bump();
        $this->persons->bump();
        $this->listings->bustMajlisListing();
        $this->catalogs->bustAddress();
        $this->eventSubmissionOptions->invalidate();
    }
}
