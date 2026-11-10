<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Models\User;
use App\Services\ShareTrackingService;
use App\Shared\ShareTracking\SignupRecorder;
use Illuminate\Http\Request;

final readonly class ShareTrackingSignupRecorder implements SignupRecorder
{
    public function __construct(private ShareTrackingService $shareTracking) {}

    public function recordSignup(string $accountId, ?Request $request = null): void
    {
        $user = User::query()->find($accountId);

        if (! $user instanceof User) {
            return;
        }

        $this->shareTracking->recordSignup($user, $request);
    }
}
