<?php

declare(strict_types=1);

namespace App\Shared\Submission;

/**
 * Submission-facing validation port implemented by the owner of spaces.
 *
 * The shared submission pipeline must not import concrete feature APIs.
 */
interface SpaceSelectionEligibility
{
    /** @param list<string> $spaceIds */
    public function validateInstitutionSelection(string $institutionId, array $spaceIds): void;

    /** @param list<string> $spaceIds */
    public function validateVenueSelection(string $venueId, array $spaceIds): void;
}
