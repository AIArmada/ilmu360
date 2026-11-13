<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

use App\Shared\Engagement\EngagementSubjectType;

/**
 * Read-only follow state for account cards and directory surfaces.
 *
 * Implementations may query the package engagement tables, but callers receive
 * only immutable scalar state.
 */
interface SubjectFollows
{
    public function followStateFor(
        string $accountId,
        EngagementSubjectType $subjectType,
        string $subjectId,
    ): FollowState;

    /**
     * @param  list<string>  $subjectIds
     * @return array<string, FollowState>
     */
    public function followStatesFor(
        string $accountId,
        EngagementSubjectType $subjectType,
        array $subjectIds,
    ): array;

    /**
     * @return list<string>
     */
    public function followedSubjectIds(string $accountId, EngagementSubjectType $subjectType): array;

    public function followerCount(EngagementSubjectType $subjectType, string $subjectId): int;
}
