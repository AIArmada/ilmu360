<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

use App\Shared\ShareTracking\ShareRequestContext;

/** Records the affiliate/share signup fact for a newly created account. */
interface SignupRecorder
{
    public function recordSignup(string $accountId, ?ShareRequestContext $request = null): void;
}
