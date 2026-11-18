<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

interface MembershipSubjectOptionsCatalog
{
    /** @return list<array{id: string, label: string, slug: string}> */
    public function subjects(MemberSubjectType $subjectType, string $search): array;
}
