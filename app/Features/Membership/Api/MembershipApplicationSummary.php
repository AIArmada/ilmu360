<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Scalar snapshot of one membership application.
 */
final readonly class MembershipApplicationSummary
{
    public function __construct(
        public string $id,
        public MembershipSubjectType $subjectType,
        public string $subjectId,
        public string $applicantId,
        public string $status,
        public ?string $submittedAt = null,
    ) {}
}
