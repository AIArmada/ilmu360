<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Membership\MembershipSubjectType;

/**
 * Scalar request for submitting one membership application.
 */
final readonly class SubmitMembershipApplicationInput
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public MembershipSubjectType $subjectType,
        public string $subjectId,
        public string $accountId,
        public string $justification,
        public array $meta = [],
    ) {}
}
