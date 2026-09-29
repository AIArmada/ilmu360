<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Actions\Auth\ClaimGuestSubmissionsAction;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class ClaimGuestSubmissionsOnVerified implements ShouldHandleEventsAfterCommit
{
    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        ClaimGuestSubmissionsAction::flashNotice(
            ClaimGuestSubmissionsAction::run($event->user)
        );
    }
}
