<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use App\Features\Events\Actions\Authoring\SyncEventLocationsAction;
use App\Features\Events\Models\Event;

final readonly class EventLocationSyncObserver
{
    public function __construct(private SyncEventLocationsAction $locations) {}
    public function updated(Event $event): void
    {
        if (! $event->wasChanged(['default_venue_id', 'institution_id'])) {
            return;
        }

        $this->locations->syncSelectedPrimaryLocation($event);
    }
}
