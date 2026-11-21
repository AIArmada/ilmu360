<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Places\Api\SpaceLocation;

final readonly class SpaceLocationResolver implements SpaceLocation
{
    public function name(?string $spaceNameSnapshot, ?string $venueSpaceName): ?string
    {
        $snapshot = trim($spaceNameSnapshot ?? '');

        return $snapshot !== '' ? $snapshot : $venueSpaceName;
    }

    public function effectiveCapacity(?string $venueSpaceId, ?int $venueSpaceCapacity, ?string $institutionId = null): ?int
    {
        if ($venueSpaceId === null) {
            return null;
        }

        if ($institutionId !== null) {
            $space = app(InstitutionLookup::class)->institutionSpaceCapacity($institutionId, $venueSpaceId);

            if ($space['found']) {
                return $space['capacity'];
            }
        }

        return $venueSpaceCapacity;
    }
}
