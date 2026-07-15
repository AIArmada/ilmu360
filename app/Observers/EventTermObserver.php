<?php

declare(strict_types=1);

namespace App\Observers;

use AIArmada\Events\Models\EventTerm;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class EventTermObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PublicListingsCache $publicListingsCache,
    ) {}

    public function saved(EventTerm $term): void
    {
        if (! $term->wasRecentlyCreated && ! $term->wasChanged()) {
            return;
        }

        $this->publicListingsCache->bustMajlisListing();
    }

    public function deleted(EventTerm $term): void
    {
        $this->publicListingsCache->bustMajlisListing();
    }
}
