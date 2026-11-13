<?php

declare(strict_types=1);

namespace App\Shared\Engagement;

use App\Enums\ShareOutcomeType;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

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
        ?Model $subject,
        ?Authenticatable $actor,
        ?Request $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseBookmark(
        string $bookmarkId,
        string $reason,
        ?Authenticatable $actor = null,
    ): void;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordResponse(
        string $responseId,
        ?Model $subject,
        ?Authenticatable $actor,
        ?Request $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseResponse(
        string $responseId,
        string $reason,
        ?Authenticatable $actor = null,
    ): void;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordFollow(
        ShareOutcomeType $type,
        string $followId,
        ?Model $subject,
        ?Authenticatable $actor,
        ?Request $request,
        array $metadata,
        ?CarbonInterface $occurredAt = null,
    ): void;

    public function reverseFollow(
        ShareOutcomeType $type,
        string $followId,
        string $reason,
        ?Authenticatable $actor = null,
    ): void;
}
