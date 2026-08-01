<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\CommerceSupport\Models\Language;
use App\Support\Cache\SelectionCatalogCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class LanguageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SelectionCatalogCache $selectionCatalogCache) {}

    public function saved(Language $language): void
    {
        if ($language->wasRecentlyCreated || $language->wasChanged()) {
            $this->selectionCatalogCache->bustLanguages();
        }
    }

    public function deleted(Language $language): void
    {
        $this->selectionCatalogCache->bustLanguages();
    }
}
