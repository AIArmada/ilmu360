<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

/**
 * Registration confirmation notification boundary.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Notifications, Registration/Notifications, Registration/EntryPoints
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
