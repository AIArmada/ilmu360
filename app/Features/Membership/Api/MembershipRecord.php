<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

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
