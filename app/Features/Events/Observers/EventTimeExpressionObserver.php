<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use AIArmada\Events\Features\Catalog\Models\EventTimeExpression;
use App\Features\Discovery\Api\PublicListingsCacheInvalidator;
use App\Features\Events\Actions\Discovery\SyncEventSearchIndexAction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class EventTimeExpressionObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private SyncEventSearchIndexAction $searchIndex,
        private PublicListingsCacheInvalidator $publicListingsCache,
    ) {}

    public function saved(EventTimeExpression $expression): void
    {
        $this->publicListingsCache->bustMajlisListing();
        $this->searchIndex->handle((string) $expression->event_id);
    }

    public function deleted(EventTimeExpression $expression): void
    {
        $this->publicListingsCache->bustMajlisListing();
        $this->searchIndex->handle((string) $expression->event_id);
    }
}
