<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

/**
 * Venue-owned authoring candidate reads.
 *
 * venue option provider.
 */
interface VenueAuthoringCandidates
{
    /**
     * Venues available for authoring, keyed by id.
     *
     * @return array<string, string>
     */
    public function options(?string $countryId = null): array;

    public function canUse(string $venueId): bool;

    public function belongsToCountry(string $venueId, string $countryId): bool;
}
