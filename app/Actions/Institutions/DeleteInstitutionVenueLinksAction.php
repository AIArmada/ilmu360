<?php

declare(strict_types=1);

namespace App\Actions\Institutions;

use App\Models\InstitutionVenue;

/**
 * Remove an institution's venue bridge rows without touching venues.
 */
final readonly class DeleteInstitutionVenueLinksAction
{
    public function handle(string $institutionId): void
    {
        InstitutionVenue::query()->where('institution_id', $institutionId)->delete();
    }

    /**
     * Remove a venue's institution bridge rows without touching institutions.
     */
    public function forVenue(string $venueId): void
    {
        InstitutionVenue::query()->where('venue_id', $venueId)->delete();
    }
}
