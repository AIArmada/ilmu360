<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Read boundary for membership applications.
 */
interface MembershipApplications
{
    /**
     * @return list<MembershipApplicationSummary>
     */
    public function forApplicant(string $accountId): array;

    /**
     * @return list<MembershipApplicationSummary>
     */
    public function forReviewer(string $accountId): array;

    /**
     * Applications for one subject, optionally narrowed by status.
     *
     * @return list<MembershipApplicationSummary>
     */
    public function forSubject(
        MembershipSubjectType $subjectType,
        string $subjectId,
        ?string $status = null,
    ): array;

    public function find(string $applicationId): ?MembershipApplicationDetail;

    public function findForApplicant(string $accountId, string $applicationId): ?MembershipApplicationDetail;

    /**
     * Submit a pending application for one subject on behalf of an account.
     *
     * Delegates the application lifecycle to the canonical package action and
     * keeps app-side duplicate and invitation guards in the feature.
     */
    public function submit(SubmitMembershipApplicationInput $input): MembershipApplicationDetail;

    /**
     * Remove an application that could not be completed with its evidence.
     */
    public function discard(string $applicationId): void;

    /**
     * Cancel one pending application owned by the account.
     *
     * Returns null when the application does not belong to the account.
     */
    public function cancelForApplicant(string $accountId, string $applicationId): ?MembershipApplicationDetail;

    /**
     * Approve a pending application on behalf of a reviewer.
     *
     * Delegates the transition, role grant and applicant notification to the
     * canonical package action and returns the committed review snapshot.
     * Unknown role names fall back to editor, matching the existing UI flows.
     */
    public function approve(
        string $applicationId,
        string $reviewerAccountId,
        string $grantedRole,
        ?string $note = null,
    ): MembershipApplicationDetail;

    /**
     * Reject a pending application on behalf of a reviewer.
     *
     * Delegates the transition and applicant notification to the canonical
     * package action and returns the committed review snapshot.
     */
    public function reject(
        string $applicationId,
        string $reviewerAccountId,
        ?string $note = null,
    ): MembershipApplicationDetail;
}
