<?php

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Seating\Models\SeatMap;

/**
 * Synchronizes the seat maps selected for an event.
 */
final readonly class SyncEventSeatMapsAction
{
    /**
     * @param  list<string>  $seatMapIds
     */
    public function handle(PackageEvent $event, array $seatMapIds): void
    {
        $event->seatMaps()->whereNotIn('id', $seatMapIds)->delete();

        SeatMap::query()->whereIn('id', $seatMapIds)->update([
            'seatable_type' => $event->getMorphClass(),
            'seatable_id' => $event->getKey(),
        ]);
    }
}
