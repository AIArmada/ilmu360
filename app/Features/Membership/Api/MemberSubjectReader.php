<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

interface MemberSubjectReader
{
    public function subjectType(): MemberSubjectType;

    /**
     * @param  list<string>  $subjectIds
     * @return list<MemberSubjectRecord>
     */
    public function forIds(array $subjectIds, string $orderColumn, string $direction): array;
}
