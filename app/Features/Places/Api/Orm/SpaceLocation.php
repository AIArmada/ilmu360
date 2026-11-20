<?php

declare(strict_types=1);

namespace App\Features\Places\Api\Orm;

use AIArmada\Events\Models\EventLocation;

/**
 * Venue-space presentation boundary for event payloads.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Services, Events/EntryPoints, Institutions/EntryPoints, Places/Services
 */
interface SpaceLocation
{
    public function name(?EventLocation $location): ?string;

    public function effectiveCapacity(?EventLocation $location, ?string $institutionId = null): ?int;
}
