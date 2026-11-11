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
}
