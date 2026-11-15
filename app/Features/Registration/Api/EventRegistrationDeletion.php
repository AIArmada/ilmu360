<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

/**
 * Deletion boundary for Registration-owned records.
 *
 * Events delegates event cleanup here before deleting the event row. The
 * Registration model delegates participant-child cleanup before the vendor
 * registration cascade runs.
 */
interface EventRegistrationDeletion
{
    public function assertEventRegistrationsCanBeDeleted(string $eventId): void;

    public function deleteForEvent(string $eventId): void;

    /**
     * Clean participant-owned children and delete descendant registrations
     * through the configured app model before the vendor cascade removes them.
     */
    public function deleteParticipantChildrenBeforeVendorCascade(string $registrationId): void;
}
