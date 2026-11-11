<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Read-only admission facts for other capabilities.
 *
 * Registration owns the admission tables (R1 onward). Callers that only need
 * to know whether an event already has registrations go through this scalar
 * boundary instead of querying the Registration model or event relation.
 */
interface AdmissionFacts
{
    public function hasRegistrations(string $eventId): bool;
}
