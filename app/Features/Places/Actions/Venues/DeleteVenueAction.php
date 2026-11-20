<?php

declare(strict_types=1);

namespace App\Features\Places\Actions\Venues;

use App\Features\Places\Models\Venue;
use App\Features\Places\Persistence\VenueWriter;
use App\Platform\Models\MorphModelResolver;

/**
 * Delete a venue through the owning feature.
 *
 * The Venue model keeps its package hooks and deleted-event institution link
 * cleanup; the Places writer owns the transactional row delete.
 */
final readonly class DeleteVenueAction
{
    public function __construct(
        private VenueWriter $venueWriter,
    ) {}

    public function handle(Venue $venue): void
    {
        MorphModelResolver::assert($venue, 'venue');

        $this->venueWriter->delete($venue);
    }
}
