<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

use AIArmada\Events\Models\EventLocation;

/**
 * Venue-space presentation boundary for event payloads.
 */
interface SpaceLocation
{
    public function name(?EventLocation $location): ?string;

    public function effectiveCapacity(?EventLocation $location, ?string $institutionId = null): ?int;
}
