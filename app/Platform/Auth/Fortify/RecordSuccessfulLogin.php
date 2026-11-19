<?php

declare(strict_types=1);

namespace App\Platform\Auth\Fortify;

use App\Features\Events\Api\GuestEventSubmissionClaims;
use App\Features\Identity\Api\GuestSubmissionNotice;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use Closure;
use Illuminate\Http\Request;

final class RecordSuccessfulLogin
{
    public function __invoke(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            app(SignalRecorder::class)->record(
                eventName: 'auth.login',
                eventCategory: 'auth',
                actorId: (string) $user->getKey(),
                properties: [
                    'method' => 'password',
                    'created_account' => false,
                ],
                request: $request,
            );

            GuestSubmissionNotice::flash(
                app(GuestEventSubmissionClaims::class)->claimForAccount((string) $user->getKey())
            );
        }

        return $response;
    }
}
