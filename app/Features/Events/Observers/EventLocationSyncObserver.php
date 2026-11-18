<?php

declare(strict_types=1);

namespace App\Features\Events\Observers;

use App\Features\Events\Models\Event;

final class EventLocationSyncObserver
{
    public function updated(Event $event): void
    {
        if (! $event->wasChanged(['default_venue_id', 'institution_id'])) {
            return;
        }

        $event->syncPrimaryLocationFromSelection();
    }
}
