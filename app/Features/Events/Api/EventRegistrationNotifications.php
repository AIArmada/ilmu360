<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Registration confirmation notification boundary.
 */
interface EventRegistrationNotifications
{
    /**
     * @param  object  $registration  Hydrated registration model.
     */
    public function registrationConfirmed(object $registration): void;

    /**
     * Send the check-in confirmation notification for the given check-in.
     */
    public function checkInConfirmed(string $checkinId): void;
}
