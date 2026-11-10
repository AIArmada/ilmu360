<?php

declare(strict_types=1);

namespace App\Observers;

use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Models\User;
use App\Shared\Directory\SubmissionEligibilitySync;

/**
 * Conventional model-event entry point for identity-owned account side effects.
 *
 * Kept outside the User model so authentication scaffolding stays conventional
 * and feature workflows remain discoverable in their owning capability.
 */
final readonly class UserObserver
{
    public function __construct(
        private SubmissionEligibilitySync $submissionEligibilitySync,
        private SignupAttributionWorkflow $signupAttribution,
    ) {}

    public function updated(User $user): void
    {
        if ($user->wasChanged(['email_verified_at', 'phone_verified_at'])) {
            $this->signupAttribution->confirmForAccount((string) $user->getKey());
        }

        if (! $user->wasChanged('phone_verified_at')) {
            return;
        }

        $this->submissionEligibilitySync->syncForAccount((string) $user->getKey());
    }
}
