<?php

declare(strict_types=1);

namespace App\Features\Places\Api\Orm;

/**
 * Venue-owned authoring candidate reads.
 *
 * @internal No model transport: every method returns final labels or ids.
 *
 * Consumers: Contributions EntitySubmissionAccess and the Events submit-event
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
