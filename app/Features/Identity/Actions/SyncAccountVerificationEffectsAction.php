<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\AccountSubmissionEligibility;
use App\Features\Identity\Api\SignupAttributionWorkflow;
use App\Models\User;

final readonly class SyncAccountVerificationEffectsAction
{
    public function __construct(
        private AccountSubmissionEligibility $submissionEligibilitySync,
        private SignupAttributionWorkflow $signupAttribution,
    ) {}

    public function handle(User $user): void
    {
        if ($user->wasChanged(['email_verified_at', 'phone_verified_at'])) {
            $this->signupAttribution->confirmForAccount((string) $user->getKey());
        }

        if ($user->wasChanged('phone_verified_at')) {
            $this->submissionEligibilitySync->syncForAccount((string) $user->getKey());
        }
    }
}
