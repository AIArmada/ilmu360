<?php

declare(strict_types=1);

namespace App\Shared\Membership;

final readonly class MembershipRecord
{
    public function __construct(
        public MembershipSubjectType $subjectType,
        public string $subjectId,
        public string $accountId,
        public string $role,
    ) {}
}
