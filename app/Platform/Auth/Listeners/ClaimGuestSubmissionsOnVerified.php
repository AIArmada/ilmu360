<?php

declare(strict_types=1);

namespace App\Platform\Auth\Listeners;

use App\Models\User;
use App\Shared\Auth\GuestEventSubmissionClaims;
use App\Shared\Auth\GuestSubmissionNotice;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class ClaimGuestSubmissionsOnVerified implements ShouldHandleEventsAfterCommit
{
    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        GuestSubmissionNotice::flash(
            app(GuestEventSubmissionClaims::class)->claimForAccount((string) $event->user->getKey())
        );
    }
}
