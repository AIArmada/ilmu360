<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Claims unowned guest event submissions for a verified account.
 *
 * Identity/auth code calls this boundary when a visitor's email becomes
 * verified or they sign in; the Events capability owns the submission and
 * event-creator attribution writes.
 */
interface GuestSubmissionClaims
{
    /**
     * Link matching guest submissions to the account and return the count.
     */
    public function claimForAccount(string $accountId): int;
}
