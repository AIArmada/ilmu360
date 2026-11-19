<?php

declare(strict_types=1);

namespace App\Features\Engagement\Api;

use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Shared\Auth\AccountActor;
use App\Shared\ShareTracking\ShareRequestContext;
use Carbon\CarbonInterface;

/**
 * Records and reverses the share outcomes produced by the engagement surfaces
 * (event save, event going, directory follow).
 *
 * The caller owns the metadata shape because the surfaces intentionally differ:
 * event saves/going and the follow API emit `subject_id`/`subject_type`, while
 * the directory card records a `{type}_id` key. The recorder must not normalise
 * those shapes.
 *
 * Restore and event-delete cleanup deliberately do not use this contract. The
 * account lifecycle contributor and the event cleanup action delete package
 * engagement rows directly, and those transitions must never reverse or
 * re-record a share outcome.
 */
interface EngagementShareOutcomes
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordBookmark(
        string $bookmarkId,
        ?ShareSubject $subject,
        ?AccountActor $actor,
        ?ShareRequestContext $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseBookmark(
        string $bookmarkId,
        string $reason,
        ?AccountActor $actor = null,
    ): void;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordResponse(
        string $responseId,
        ?ShareSubject $subject,
        ?AccountActor $actor,
        ?ShareRequestContext $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseResponse(
        string $responseId,
        string $reason,
        ?AccountActor $actor = null,
    ): void;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordFollow(
        ShareOutcomeType $type,
        string $followId,
        ?ShareSubject $subject,
        ?AccountActor $actor,
        ?ShareRequestContext $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseFollow(
        ShareOutcomeType $type,
        string $followId,
        string $reason,
        ?AccountActor $actor = null,
    ): void;
}
