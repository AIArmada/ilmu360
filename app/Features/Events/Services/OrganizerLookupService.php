<?php

declare(strict_types=1);

namespace App\Features\Events\Services;

use App\Features\Events\Api\OrganizerLookup;

/**
 * Boundary adapter over the static organizer-kind resolver.
 */
final readonly class OrganizerLookupService implements OrganizerLookup
{
    public function kindForId(?string $id): ?string
    {
        return OrganizerResolver::kindForId($id);
    }

    public function involvementMatchesInstitution(
        ?string $involveableType,
        ?string $involveableId,
        string $institutionId,
    ): bool {
        return OrganizerResolver::involvementMatchesInstitution($involveableType, $involveableId, $institutionId);
    }
}
