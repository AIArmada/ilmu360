<?php

declare(strict_types=1);

namespace App\Features\Membership\Services;

use App\Features\Institutions\Api\InstitutionCatalog;
use App\Features\Membership\Api\MembershipSubjectOptionsCatalog;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Api\PersonCatalog;

final readonly class EloquentMembershipSubjectOptionsCatalog implements MembershipSubjectOptionsCatalog
{
    public function __construct(
        private InstitutionCatalog $institutions,
        private PersonCatalog $persons,
    ) {}

    public function subjects(MemberSubjectType $subjectType, string $search): array
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => $this->institutions->membershipApplicationSubjects($search),
            MemberSubjectType::Person => $this->persons->membershipApplicationSubjects($search),
            default => [],
        };
    }
}
