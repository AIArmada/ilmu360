<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Persons\Models\TitleCategory;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class TitleCategoryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SelectionCatalogCache $selectionCatalogCache) {}

    public function saved(TitleCategory $category): void
    {
        if ($category->wasRecentlyCreated || $category->wasChanged()) {
            $this->selectionCatalogCache->bustTitles();
        }
    }

    public function deleted(TitleCategory $category): void
    {
        $this->selectionCatalogCache->bustTitles();
    }
}
