<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Atomic advanced event shell, first occurrence, associations and media. */
interface AdvancedEventCreator
{
    /** @param array<string, mixed> $form */
    public function create(
        string $actorId,
        array $form,
        string $startsAt,
        string $endsAt,
        string $timezone,
        ?string $primaryOrganizerType,
        ?string $primaryOrganizerId,
        ?string $locationInstitutionId,
        ?string $organizationId = null,
        ?string $locationVenueId = null,
    ): string;
}
