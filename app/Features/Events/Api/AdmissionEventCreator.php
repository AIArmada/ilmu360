<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Create an advanced event and configure canonical registration/ticketing. */
interface AdmissionEventCreator
{
    /**
     * @param array<string, mixed> $form
     * @param array<int, array<string, mixed>> $tickets
     * @param array<string, mixed> $seating
     */
    public function create(
        string $actorId,
        array $form,
        string $startsAt,
        string $endsAt,
        string $timezone,
        ?string $primaryOrganizerType,
        ?string $primaryOrganizerId,
        ?string $locationInstitutionId,
        ?string $organizationId,
        string $registrationMode,
        string $pricingMode,
        array $tickets,
        array $seating = [],
        ?string $locationVenueId = null,
    ): string;
}
