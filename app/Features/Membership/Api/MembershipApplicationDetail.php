<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

/**
 * Review snapshot for one membership application.
 */
final readonly class MembershipApplicationDetail
{
    public function __construct(
        public MembershipApplicationSummary $application,
        public ?string $reviewerId = null,
        public ?string $reviewerNote = null,
        public ?string $grantedRole = null,
        public ?string $reviewedAt = null,
        public ?string $cancelledAt = null,
    ) {}
}
