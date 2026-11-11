<?php

namespace App\Observers;

use App\Features\Directory\Api\VenueWorkflow;
use App\Models\Venue;
use App\Platform\Cache\PublicListingsCache;
use App\Platform\Models\Concerns\SyncsCurrentAndPreviousValues;
use App\Platform\Routing\SyncSlugRedirectAction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class VenueObserver implements ShouldHandleEventsAfterCommit
{
    use SyncsCurrentAndPreviousValues;

    public function __construct(
        protected VenueWorkflow $venueWorkflow,
        protected SyncSlugRedirectAction $syncSlugRedirectAction,
        protected PublicListingsCache $publicListingsCache
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
        $this->syncSlugRedirectAction->purgeForModel($venue);
        $this->venueWorkflow->syncSlugsForName($venue->name);
        $this->publicListingsCache->bustMajlisListing();
    }
}
