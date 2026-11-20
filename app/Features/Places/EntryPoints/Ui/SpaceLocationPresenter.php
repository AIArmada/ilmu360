<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Ui;

use AIArmada\Events\Models\EventLocation;
use App\Features\Institutions\Api\InstitutionLookup;

final class SpaceLocationPresenter
{
    public static function name(?EventLocation $location): ?string
    {
        if (! $location instanceof EventLocation) {
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
            $space = app(InstitutionLookup::class)
                ->institutionSpaceCapacity($institutionId, (string) $location->venue_space_id);

            if ($space['found']) {
                return $space['capacity'];
            }
        }

        return $location->venueSpace?->capacity === null ? null : (int) $location->venueSpace->capacity;
    }
}
