<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Projection;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Persons\Api\PersonListingCacheVersion;

final readonly class InvalidateEventKeyPersonCardsAction
{
    public function __construct(
        private PersonListingCacheVersion $personCacheVersion,
        private PublicListingsCacheInvalidator $publicListingsCache,
    ) {}

    public function handle(): void
    {
        $this->publicListingsCache->bustHomepageStats();
        $this->personCacheVersion->bump();
    }
}
