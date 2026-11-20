<?php

declare(strict_types=1);

namespace App\Features\Places\Services;

use AIArmada\Events\Models\EventLocation;
use App\Features\Places\Api\Orm\SpaceLocation;
use App\Features\Places\EntryPoints\Ui\SpaceLocationPresenter;

/**
 * Boundary adapter over the space-location presenter used by event payloads.
 */
final readonly class SpaceLocationResolver implements SpaceLocation
{
    public function name(?EventLocation $location): ?string
    {
        return SpaceLocationPresenter::name($location);
    }

    public function effectiveCapacity(?EventLocation $location, ?string $institutionId = null): ?int
    {
        return SpaceLocationPresenter::effectiveCapacity($location, $institutionId);
    }
}
