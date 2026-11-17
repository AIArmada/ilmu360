<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/** Account-login integration for the Events-owned guest submission claim. */
interface GuestEventSubmissionClaims
{
    public function claimForAccount(string $accountId): int;
}
