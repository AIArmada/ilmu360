<?php

declare(strict_types=1);

namespace App\Platform\Auth\Listeners;

use App\Models\User;
use App\Platform\Auth\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class SendRegisteredUserEmails implements ShouldHandleEventsAfterCommit
{
    public function handle(Registered $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if (! is_string($event->user->email) || trim($event->user->email) === '') {
            return;
        }

        $event->user->notify(new WelcomeNotification);
    }
}
