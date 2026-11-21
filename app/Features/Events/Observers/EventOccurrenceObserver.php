<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use AIArmada\Events\Models\EventOccurrence;
use App\Features\Events\Actions\Discovery\RefreshOccurrenceDiscoveryAction;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class EventOccurrenceObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RefreshOccurrenceDiscoveryAction $discovery,
    ) {}

    public function saved(EventOccurrence $occurrence): void
    {
        $this->discovery->afterSaved((string) $occurrence->event_id);
    }

    public function deleted(EventOccurrence $occurrence): void
    {
        $this->discovery->afterDeleted((string) $occurrence->event_id);
    }
}
