<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Discovery;

use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Api\ShareCardWarmScheduler;

final readonly class RefreshOccurrenceDiscoveryAction
{
    public function __construct(
        private PublicListingsCacheInvalidator $listings,
        private SyncEventSearchIndexAction $searchIndex,
        private ShareCardWarmScheduler $cards,
    ) {}

    public function afterSaved(string $eventId): void
    {
        $this->listings->bustMajlisListing();
        $this->searchIndex->handle($eventId);
        $this->cards->prewarmForEvent($eventId);
    }

    public function afterDeleted(string $eventId): void
    {
        $this->listings->bustMajlisListing();
        $this->searchIndex->handle($eventId);
    }
}
