<?php

namespace App\Listeners\Notifications;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

class RecordNotificationSent
{
    public function handle(NotificationSent $event): void
    {
        if (! $event->notifiable instanceof User) {
            return;
        }
    }
}
