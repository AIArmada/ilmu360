<?php

declare(strict_types=1);

namespace App\Shared\Engagement;

use Carbon\CarbonImmutable;

interface EngagementReader
{
    /** @return list<string> */
    public function followedSubjectIds(string $accountId, EngagementSubjectType $subjectType): array;

    /** @return list<string> */
    public function savedEventIds(string $accountId): array;

    /** @return list<string> */
    public function goingEventIds(string $accountId): array;

    public function hasSavedEvent(string $accountId, string $eventId): bool;

    public function isGoingToEvent(string $accountId, string $eventId): bool;

    /** @return list<string> */
    public function followerAccountIds(EngagementSubjectType $subjectType, string $subjectId): array;

    public function bookmarkActivity(string $bookmarkId): ?EngagementActivity;

    public function responseActivity(string $responseId): ?EngagementActivity;

    public function followActivity(string $followId): ?EngagementActivity;

    public function hasEarlierBookmarkActivity(
        string $actorType,
        string $actorId,
        CarbonImmutable $before,
        string $excludingId,
    ): bool;

    public function hasEarlierResponseActivity(
        string $actorType,
        string $actorId,
        CarbonImmutable $before,
        string $excludingId,
    ): bool;

    public function hasEarlierFollowActivity(
        string $actorType,
        string $actorId,
        CarbonImmutable $before,
        string $excludingId,
    ): bool;
}
