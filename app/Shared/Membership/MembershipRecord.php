<?php

declare(strict_types=1);

namespace App\Shared\Membership;

use App\Features\Membership\Api\MemberSubjectType;

final readonly class MembershipRecord
{
    public function __construct(
        public MemberSubjectType $subjectType,
        public string $subjectId,
        public string $accountId,
        public string $role,
    ) {}
}
