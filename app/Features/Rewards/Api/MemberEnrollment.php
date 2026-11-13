<?php

declare(strict_types=1);

namespace App\Features\Rewards\Api;

/**
 * Scalar member enrollment boundary.
 *
 * Enrollment, leave and reacceptance move behind this contract in Rw2b; this
 * slice exposes only the preclaim backfill that enrollment and reacceptance
 * run after the package write commits. Every value is a scalar identity:
 * no member model, program model or actor object crosses the boundary.
 */
interface MemberEnrollment
{
    /**
     * Backfill reward evaluation for eligible receipts in the published
     * version's preclaim lookback window.
     */
    public function preclaim(
        string $programId,
        string $memberId,
        string $recipientType,
        string $versionId,
    ): void;
}
