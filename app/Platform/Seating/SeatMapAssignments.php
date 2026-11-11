<?php

declare(strict_types=1);

namespace App\Platform\Seating;

use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Seating\Models\SeatMap;

/**
 * Owns the event-to-seat-map attachment write.
 *
 * Seat maps are a package-canonical, polymorphic resource: feature code picks
 * the ids but must not mutate the vendor seating models itself.
 */
final class SeatMapAssignments
{
    /**
     * Replace the event's seat-map selection with the given maps.
     *
     * Maps no longer selected are deleted through the event relation first,
     * then every selected map is re-pointed at the event so a map held by
     * another seatable moves here.
     *
     * @param  list<string>  $seatMapIds
     */
    public function sync(PackageEvent $event, array $seatMapIds): void
    {
        $event->seatMaps()->whereNotIn('id', $seatMapIds)->delete();

        SeatMap::query()->whereIn('id', $seatMapIds)->update([
            'seatable_type' => $event->getMorphClass(),
            'seatable_id' => $event->getKey(),
        ]);
    }
}
