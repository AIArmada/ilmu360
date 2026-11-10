<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final readonly class RecordVerifiedEmail implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private SignalRecorder $signals,
    ) {}

    public function handle(Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->signals->record(
            eventName: 'auth.email_verified',
            eventCategory: 'auth',
            actorId: (string) $event->user->getKey(),
            request: request(),
        );
    }
}
