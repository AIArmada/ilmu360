<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Events\Api\GuestEventSubmissionClaims;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Http\Request;

/**
 * Records the authenticated login and claims eligible guest event submissions.
 */
final readonly class RecordSuccessfulLoginAction
{
    public function __construct(
        private SignalRecorder $signals,
        private GuestEventSubmissionClaims $guestSubmissions,
    ) {}

    public function handle(User $user, Request $request): int
    {
        $this->signals->record(
            eventName: 'auth.login',
            eventCategory: 'auth',
            actorId: (string) $user->getKey(),
            properties: [
                'method' => 'password',
                'created_account' => false,
            ],
            request: $request,
        );

        return $this->guestSubmissions->claimForAccount((string) $user->getKey());
    }
}
