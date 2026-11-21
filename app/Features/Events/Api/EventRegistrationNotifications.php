<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Event-owned notification commands invoked after Registration completes
 * its transaction. Only stable registration and check-in identities cross
 * the feature boundary; the owners resolve their own persisted facts.
 */
interface EventRegistrationNotifications
{
    public function registrationConfirmed(string $registrationId): void;

    public function checkInConfirmed(string $checkinId): void;
}
