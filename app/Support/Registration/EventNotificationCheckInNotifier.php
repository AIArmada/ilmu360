<?php

declare(strict_types=1);

namespace App\Support\Registration;

use App\Models\EventCheckin;
use App\Services\Notifications\EventNotificationService;
use App\Shared\Registration\CheckInNotifier;

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
