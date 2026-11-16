<?php

declare(strict_types=1);

namespace App\Features\Places\Api;

use App\Shared\Submission\SpaceSelectionEligibility;

/**
 * Scalar space-selection contract used by submission and authoring workflows.
 * Places owns the implementation; callers receive options, never queries.
 */
interface SpaceEligibility extends SpaceSelectionEligibility
{
    /** @return array<string, string> */
    public function catalogOptions(bool $activeOnly = false): array;

    /** @return array<string, string> */
    public function institutionOptions(string $institutionId, bool $activeOnly = false): array;

    /** @return array<string, string> */
    public function venueOptions(string $venueId, bool $activeOnly = false): array;
}
