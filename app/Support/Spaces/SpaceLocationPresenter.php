<?php

declare(strict_types=1);

namespace App\Support\Spaces;

use AIArmada\Events\Models\EventLocation;
use App\Models\Institution;

final class SpaceLocationPresenter
{
    public static function name(?EventLocation $location): ?string
    {
        if ($location === null) {
            return null;
        }

        $snapshot = trim((string) ($location->space_name_snapshot ?? ''));

        return $snapshot !== '' ? $snapshot : $location->venueSpace?->name;
    }

    public static function effectiveCapacity(?EventLocation $location, ?string $institutionId = null): ?int
    {
        if ($location?->venue_space_id === null) {
            return null;
        }

        if ($institutionId !== null) {
            $space = Institution::query()
                ->find($institutionId)
                ?->spaces()
                ->whereKey($location->venue_space_id)
                ->first();

            if ($space !== null) {
                return $space->effectiveCapacity();
            }
        }

        return $location->venueSpace?->capacity === null ? null : (int) $location->venueSpace->capacity;
    }
}
