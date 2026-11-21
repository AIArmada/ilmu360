<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Projection;

use AIArmada\Events\Models\EventTerm;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Shared\Cache\SelectionCatalogCache;

final readonly class InvalidateEventTermCatalogAction
{
    public function __construct(
        private PublicListingsCacheInvalidator $publicListingsCache,
        private SelectionCatalogCache $selectionCatalogCache,
    ) {}

    public function saved(EventTerm $term): void
    {
        if (! $term->wasRecentlyCreated && ! $term->wasChanged()) {
            return;
        }

        $this->publicListingsCache->bustMajlisListing();
        $this->selectionCatalogCache->bustEventCategories();
    }

    public function deleted(EventTerm $term): void
    {
        $this->publicListingsCache->bustMajlisListing();
        $this->selectionCatalogCache->bustEventCategories();
    }
}
