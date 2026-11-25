<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Projection;

use AIArmada\Events\Features\Taxonomy\Models\EventTerm;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Shared\Cache\SelectionCatalog;

final readonly class InvalidateEventTermCatalogAction
{
    public function __construct(
        private PublicListingsCacheInvalidator $publicListingsCache,
        private SelectionCatalog $selectionCatalog,
        private EventSubmissionOptionsCache $submissionOptionsCache,
    ) {}

    public function saved(EventTerm $term): void
    {
        if (! $term->wasRecentlyCreated && ! $term->wasChanged()) {
            return;
        }

        $this->publicListingsCache->bustMajlisListing();
        $this->selectionCatalog->bustEventCategories();
        $this->selectionCatalog->bustTaxonomyTerms();
        $this->submissionOptionsCache->invalidate();
    }

    public function deleted(): void
    {
        $this->publicListingsCache->bustMajlisListing();
        $this->selectionCatalog->bustEventCategories();
        $this->selectionCatalog->bustTaxonomyTerms();
        $this->submissionOptionsCache->invalidate();
    }
}
