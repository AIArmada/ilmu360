<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\User;
use App\Notifications\Auth\WelcomeNotification;
use Illuminate\Auth\Events\Registered;

final class SendRegisteredUserEmails
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
