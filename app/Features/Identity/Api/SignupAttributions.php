<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

interface SignupAttributions
{
    /**
     * Read the signup attribution recorded for the account.
     */
    public function findForAccount(string $accountId): ?SignupAttributionSnapshot;

    /**
     * Read and lock the account's signup attribution for a confirmation pass.
     */
    public function lockForAccount(string $accountId): ?SignupAttributionSnapshot;

    /**
     * Record a new, immutable signup attribution.
     */
    public function record(RecordSignupAttributionInput $input): SignupAttributionSnapshot;

    /**
     * Confirm a pending attribution after contact verification.
     */
    public function confirm(string $attributionId): ?SignupAttributionSnapshot;

    /**
     * Attach guests captured under an affiliate profile to the claiming account.
     */
    public function linkGuestReferrals(string $affiliateId, string $referrerAccountId): int;

    /**
     * Accounts that were confirmed as referred by the given account.
     *
     * @return list<string>
     */
    public function confirmedReferralAccountIds(string $referrerAccountId): array;
}
