<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

interface AccountMembershipDirectory
{
    /**
     * @return list<MemberSubjectRecord>
     */
    public function subjects(
        string $accountId,
        MemberSubjectType $subjectType,
        ?string $orderColumn = null,
        ?string $direction = null,
    ): array;

    /**
     * @return list<MembershipRecord>
     */
    public function memberships(string $accountId, MemberSubjectType $subjectType): array;
}
