<?php

declare(strict_types=1);

namespace App\Features\Discovery\Api;

/** Cross-feature invalidation of public discovery projections. */
interface PublicListingsCacheInvalidator
{
    public function bustHomepageStats(): void;

    public function bustMajlisListing(): void;
}
