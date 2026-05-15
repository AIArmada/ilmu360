<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tag;
use App\Support\Cache\PublicListingsCache;

class TagObserver
{
    public function __construct(
        protected PublicListingsCache $publicListingsCache
    ) {}

    public function saved(Tag $tag): void
    {
        $this->publicListingsCache->bustMajlisListing();
    }

    public function deleted(Tag $tag): void
    {
        $this->publicListingsCache->bustMajlisListing();
    }
}
