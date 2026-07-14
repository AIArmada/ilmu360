<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tag;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class TagObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        protected PublicListingsCache $publicListingsCache
    ) {}

    public function saved(Tag $tag): void
    {
        if (! $tag->wasRecentlyCreated && ! $tag->wasChanged()) {
            return;
        }

        $this->publicListingsCache->bustMajlisListing();
    }

    public function deleted(Tag $tag): void
    {
        $this->publicListingsCache->bustMajlisListing();
    }
}
