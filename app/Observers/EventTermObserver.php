<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Events\Models\EventTerm;
use App\Support\Cache\PublicListingsCache;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class EventTermObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private PublicListingsCache $publicListingsCache,
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
