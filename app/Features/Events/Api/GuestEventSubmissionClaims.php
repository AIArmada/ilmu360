<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Account-login integration for the Events-owned guest submission claim. */
interface GuestEventSubmissionClaims
{
    public function claimForAccount(string $accountId): int;
}
