<?php

namespace App\Features\Places\Observers;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Places\Api\VenueWorkflow;
use App\Features\SlugRedirects\Api\SlugRedirectCommands;
use App\Features\Places\Models\Venue;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class VenueObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected VenueWorkflow $venueWorkflow,
        protected SlugRedirectCommands $slugRedirectCommands,
        protected PublicListingsCacheInvalidator $publicListingsCache
    ) {}

    public function saved(Venue $venue): void
    {
        if (! $venue->wasRecentlyCreated && ! $venue->wasChanged()) {
            return;
        }

        if ($venue->wasRecentlyCreated || $venue->wasChanged('name')) {
            $this->syncCurrentAndPreviousString(
                $venue->name,
                $venue->wasChanged('name') ? ($venue->getPrevious()['name'] ?? null) : null,
                fn (string $name): bool => $this->venueWorkflow->syncSlugsForName($name),
            );
        }

        $this->publicListingsCache->bustMajlisListing();
    }

    public function deleted(Venue $venue): void
    {
        // Bridge rows are removed synchronously inside Venue::delete();
        // this after-commit observer keeps derived side effects only.
        $this->slugRedirectCommands->purge($venue->getMorphClass(), (string) $venue->getKey());
        $this->venueWorkflow->syncSlugsForName($venue->name);
        $this->publicListingsCache->bustMajlisListing();
    }
}
