<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

/**
 * Space presentation contract: consume scalar snapshots, never Eloquent models.
 */
interface SpaceLocation
{
    public function name(?string $spaceNameSnapshot, ?string $venueSpaceName): ?string;

    public function effectiveCapacity(?string $venueSpaceId, ?int $venueSpaceCapacity, ?string $institutionId = null): ?int;
}
