<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use Illuminate\Support\Facades\DB;

/**
 * Remove institution↔venue bridge rows without touching venues.
 */
final readonly class DeleteInstitutionVenueLinksAction
{
    public function handle(string $institutionId): void
    {
        DB::table('institution_venue')
            ->where('institution_id', $institutionId)
            ->delete();
    }

    /**
     * Remove a venue's institution bridge rows without touching institutions.
     */
    public function forVenue(string $venueId): void
    {
        DB::table('institution_venue')
            ->where('venue_id', $venueId)
            ->delete();
    }
}
