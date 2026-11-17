<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Jobs;

use App\Shared\Events\EventReminderNotificationDispatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchEventReminderNotifications implements ShouldQueue
{
    use Queueable;

    public function handle(EventReminderNotificationDispatch $notificationService): void
    {
        $notificationService->dispatchDueReminderNotifications();
    }
}
