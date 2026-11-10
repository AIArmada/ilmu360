<?php

declare(strict_types=1);

namespace App\Shared\Engagement;

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
}
