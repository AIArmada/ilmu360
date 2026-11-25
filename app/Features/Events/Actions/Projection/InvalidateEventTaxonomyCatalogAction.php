<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Projection;

use AIArmada\Events\Features\Taxonomy\Models\EventTaxonomy;
use App\Features\Events\Api\EventSubmissionOptionsCache;
use App\Shared\Cache\SelectionCatalog;

final readonly class InvalidateEventTaxonomyCatalogAction
{
    public function __construct(
        private SelectionCatalog $selectionCatalog,
        private EventSubmissionOptionsCache $submissionOptionsCache,
    ) {}

    public function saved(EventTaxonomy $taxonomy): void
    {
        if ($taxonomy->wasRecentlyCreated || $taxonomy->wasChanged()) {
            $this->selectionCatalog->bustEventCategories();
            $this->selectionCatalog->bustTaxonomyTerms();
            $this->submissionOptionsCache->invalidate();
        }
    }

    public function deleted(): void
    {
        $this->selectionCatalog->bustEventCategories();
        $this->selectionCatalog->bustTaxonomyTerms();
        $this->submissionOptionsCache->invalidate();
    }
}
