<?php

declare(strict_types=1);

namespace App\Features\Registration\Notifications;

use App\Features\Events\Api\EventRegistrationNotifications;
use App\Features\Registration\Api\CheckInNotifier;

/**
 * Root adapter for Registration's created-check-in notification boundary.
 *
 * The feature calls this only after its check-in transaction commits; the
 * events feature owns the confirmed check-in notification.
 */
final readonly class EventNotificationCheckInNotifier implements CheckInNotifier
{
    public function __construct(private EventRegistrationNotifications $notifications) {}

    public function notifyConfirmed(string $checkinId): void
    {
        $this->notifications->checkInConfirmed($checkinId);
    }
}
