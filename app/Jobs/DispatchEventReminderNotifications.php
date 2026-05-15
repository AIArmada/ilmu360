<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\EventNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchEventReminderNotifications implements ShouldQueue
{
    use Queueable;

    public function handle(EventNotificationService $notificationService): void
    {
        $notificationService->dispatchDueReminderNotifications();
    }
}
