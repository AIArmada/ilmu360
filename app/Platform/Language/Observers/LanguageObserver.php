<?php

declare(strict_types=1);

namespace App\Platform\Language\Observers;

use AIArmada\CommerceSupport\Features\ReferenceData\Models\Language;
use App\Shared\Cache\SelectionCatalog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class LanguageObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private SelectionCatalog $selectionCatalog) {}

    public function saved(Language $language): void
    {
        if ($language->wasRecentlyCreated || $language->wasChanged()) {
            $this->selectionCatalog->bustLanguages();
        }
    }

    public function deleted(Language $language): void
    {
        $this->selectionCatalog->bustLanguages();
    }
}
