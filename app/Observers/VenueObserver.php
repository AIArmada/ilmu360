<?php

namespace App\Observers;

use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Actions\Venues\GenerateVenueSlugAction;
use App\Models\Venue;
use App\Observers\Concerns\SyncsCurrentAndPreviousValues;
use App\Support\Cache\PublicListingsCache;

class VenueObserver
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected GenerateVenueSlugAction $generateVenueSlugAction,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PublicListingsCache $publicListingsCache
    ) {}

    public function saved(Venue $venue): void
    {
        if ($venue->wasRecentlyCreated || $venue->wasChanged('name')) {
            $this->syncCurrentAndPreviousString(
                $venue->name,
                $venue->wasChanged('name') ? ($venue->getPrevious()['name'] ?? null) : null,
                fn (string $name): bool => $this->generateVenueSlugAction->syncVenueSlugsForName($name),
            );
        }

        $this->publicListingsCache->bustMajlisListing();
    }

    public function deleted(Venue $venue): void
    {
        $this->syncSlugRedirectAction->purgeForModel($venue);
        $this->generateVenueSlugAction->syncVenueSlugsForName($venue->name);
        $this->publicListingsCache->bustMajlisListing();
    }
}
