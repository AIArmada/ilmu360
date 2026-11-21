<?php

declare(strict_types=1);

namespace App\Features\Persons\Actions\Projection;

use AIArmada\Persons\Models\TitleCategory;
use App\Shared\Cache\SelectionCatalogCache;

final readonly class InvalidateTitleCategoryCacheAction
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
