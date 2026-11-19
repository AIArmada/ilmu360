<?php

declare(strict_types=1);

namespace App\Features\Events\Notifications;

use App\Features\Events\Contracts\EventChangeNoticeNotifier;
use App\Features\Events\Models\EventChangeAnnouncement;

final readonly class EventChangeNoticeNotificationAdapter implements EventChangeNoticeNotifier
{
    public function __construct(private EventNotificationService $notifications) {}

    public function notify(string $announcementId): void
    {
        $announcement = EventChangeAnnouncement::query()
            ->with(['event', 'replacementEvent'])
            ->findOrFail($announcementId);

        $this->notifications->notifyEventChangeAnnouncement($announcement);
    }
}
