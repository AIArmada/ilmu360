<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

use App\Shared\Engagement\EngagementActivity;
use Carbon\CarbonImmutable;

/**
 * Immutable engagement activity facts used by rewards and notifications.
 */
interface EngagementActivityReader
{
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
