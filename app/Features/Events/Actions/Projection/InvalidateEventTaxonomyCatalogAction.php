<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Projection;

use AIArmada\Events\Models\EventTaxonomy;
use App\Shared\Cache\SelectionCatalogCache;

final readonly class InvalidateEventTaxonomyCatalogAction
{
    public function __construct(private SelectionCatalogCache $selectionCatalogCache) {}

    public function saved(EventTaxonomy $taxonomy): void
    {
        if ($taxonomy->wasRecentlyCreated || $taxonomy->wasChanged()) {
            $this->selectionCatalogCache->bustEventCategories();
        }
    }

    public function deleted(EventTaxonomy $taxonomy): void
    {
        $this->selectionCatalogCache->bustEventCategories();
    }
}
