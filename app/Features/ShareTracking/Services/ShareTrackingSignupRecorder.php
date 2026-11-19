<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use App\Models\User;
use App\Shared\ShareTracking\SignupRecorder;
use Illuminate\Http\Request;

final readonly class ShareTrackingSignupRecorder implements SignupRecorder
{
    public function __construct(private AffiliatesShareTrackingService $shareTracking) {}

    public function recordSignup(string $accountId, ?Request $request = null): void
    {
        $user = User::query()->find($accountId);

        if (! $user instanceof User) {
            return;
        }

        $this->shareTracking->recordSignup($user, $request instanceof Request ? \App\Shared\ShareTracking\ShareRequestContext::fromRequest($request) : null);
    }
}
