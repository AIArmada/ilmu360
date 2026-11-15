<?php

declare(strict_types=1);

namespace App\Shared\Directory;

/**
 * Scalar space-selection contract used by submission and authoring workflows.
 * Directory owns the implementation; callers receive options, never queries.
 */
interface SpaceEligibility
{
    /** @return array<string, string> */
    public function catalogOptions(bool $activeOnly = false): array;

    /** @return array<string, string> */
    public function institutionOptions(string $institutionId, bool $activeOnly = false): array;

    /** @return array<string, string> */
    public function venueOptions(string $venueId, bool $activeOnly = false): array;

    /** @param list<string> $spaceIds */
    public function validateInstitutionSelection(string $institutionId, array $spaceIds): void;

    /** @param list<string> $spaceIds */
    public function validateVenueSelection(string $venueId, array $spaceIds): void;
}
