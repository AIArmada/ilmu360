<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use App\Features\Membership\Api\MemberSubjectType;

/**
 * Read boundary for membership subjects used by account, application and
 * invitation surfaces.
 *
 * The implementation resolves records through their owner lookup contracts,
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
        MemberSubjectType $subjectType,
        int $limit = 50,
    ): array;

    public function find(MemberSubjectType $subjectType, string $subjectId): ?MembershipSubject;

    /** Resolve UUID or public slug without loading membership presentation. */
    public function resolveIdentifier(MemberSubjectType $subjectType, string $identifier): ?string;

    public function exists(MemberSubjectType $subjectType, string $subjectId): bool;
}
