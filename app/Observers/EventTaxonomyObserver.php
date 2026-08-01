<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Events\Models\EventTaxonomy;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class EventTaxonomyObserver implements ShouldHandleEventsAfterCommit
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
