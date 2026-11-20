<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Resolves the organizer identity of an event submission.
 *
 * Institution ids take precedence over person ids; the returned kind is the
 * canonical involvement discriminator stored on the event.
 */
interface OrganizerLookup
{
    public function kindForId(?string $id): ?string;

    public function involvementMatchesInstitution(
        ?string $involveableType,
        ?string $involveableId,
        string $institutionId,
    ): bool;
}
