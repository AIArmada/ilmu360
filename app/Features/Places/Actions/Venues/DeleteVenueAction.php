<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Venues;

use App\Platform\Models\MorphModelResolver;
use App\Models\Venue;

/**
 * Delete a venue through the owning feature.
 *
 * The venue model delete override invokes the Institutions venue-link removal
 * action inside its transaction, so deleting through this action preserves
 * the current lifecycle, observer and media behavior.
 */
final readonly class DeleteVenueAction
{
    public function handle(Venue $venue): void
    {
        MorphModelResolver::assert($venue, 'venue');

        $venue->delete();
    }
}
