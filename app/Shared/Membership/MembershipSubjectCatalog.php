<?php

declare(strict_types=1);

namespace App\Shared\Membership;

/**
 * Read boundary for membership subjects used by account, application and
 * invitation surfaces.
 *
 * The implementation resolves Directory records through the Directory reader,
 * organizations through the Organizations access boundary and events through
 * their registered morph alias, so callers never query foreign models.
 */
interface MembershipSubjectCatalog
{
    /**
     * Subjects of one type the account is a member of.
     *
     * @return list<MembershipSubject>
     */
    public function forAccount(
        string $accountId,
        MembershipSubjectType $subjectType,
        int $limit = 50,
    ): array;

    public function find(MembershipSubjectType $subjectType, string $subjectId): ?MembershipSubject;

    public function exists(MembershipSubjectType $subjectType, string $subjectId): bool;
}
