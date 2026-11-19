<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Shared\Media\StagedUpload;

/**
 * Request to submit one membership application and its uploaded evidence.
 */
final readonly class SubmitMembershipApplicationInput
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  list<StagedUpload>  $evidenceFiles
     */
    public function __construct(
        public MemberSubjectType $subjectType,
        public string $subjectId,
        public string $accountId,
        public string $justification,
        public array $meta = [],
        public array $evidenceFiles = [],
    ) {}
}
