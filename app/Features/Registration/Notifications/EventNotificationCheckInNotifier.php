<?php

declare(strict_types=1);

namespace App\Features\Registration\Notifications;

use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Registration\Api\CheckInNotifier;
use App\Features\Registration\Models\EventCheckin;

/**
 * Root adapter for Registration's created-check-in notification boundary.
 *
 * The feature calls this only after its check-in transaction commits; the
 * notification service remains root-owned until its later migration slice.
 */
final readonly class EventNotificationCheckInNotifier implements CheckInNotifier
{
    public function __construct(private EventNotificationService $notifications) {}

    public function notifyConfirmed(string $checkinId): void
    {
        $checkin = EventCheckin::query()->findOrFail($checkinId);

        $this->notifications->notifyCheckinConfirmed($checkin);
    }
}
