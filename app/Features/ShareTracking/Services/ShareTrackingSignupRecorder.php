<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use App\Features\ShareTracking\Api\SignupRecorder;
use App\Models\User;
use App\Shared\ShareTracking\ShareRequestContext;

final readonly class ShareTrackingSignupRecorder implements SignupRecorder
{
    public function __construct(private AffiliatesShareTrackingService $shareTracking) {}

    public function recordSignup(string $accountId, ?ShareRequestContext $request = null): void
    {
        $user = User::query()->find($accountId);

        if (! $user instanceof User) {
            return;
        }

        $this->shareTracking->recordSignup($user, $request);
    }
}
