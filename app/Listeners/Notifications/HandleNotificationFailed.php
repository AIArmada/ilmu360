<?php

namespace App\Listeners\Notifications;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationFailed;

class HandleNotificationFailed
{
    public function handle(NotificationFailed $event): void
    {
        if (! $event->notifiable instanceof User) {
            return;
        }
    }
}
