<?php

declare(strict_types=1);

namespace App\Features\Rewards\Api;

/**
 * Scalar member enrollment boundary.
 *
 * Enrollment, leave and reacceptance all cross this contract with scalar
 * identities only: no member model, program model, version model or actor
 * object is exported. The feature resolves the notifiable through
 * `Shared\Auth\AccountDirectory`, authorizes against the existing reward
 * policies, and returns immutable scalar snapshots.
 *
 * `versionId` stays nullable on purpose. API callers pass the explicitly
 * accepted version; web surfaces pass null and the feature resolves the
 * currently published version. `recordSignal` keeps the recorded product
 * asymmetry: web surfaces record the first-active `rewards.program_enrolled`
 * signal, API enrollment stays untracked.
 */
interface MemberEnrollment
{
    public function enroll(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $surface = 'member-portal',
        bool $recordSignal = true,
        ?string $acceptedVia = null,
    ): ?MemberEnrollmentSnapshot;

    public function leave(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $reason = null,
    ): void;

    public function reaccept(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $acceptedVia = 'member-portal',
    ): ?MemberEnrollmentSnapshot;

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
