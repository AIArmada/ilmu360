<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\EventChangeAnnouncement;
use App\Services\Notifications\EventNotificationService;
use App\Shared\Events\EventChangeNoticeNotifier;

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
