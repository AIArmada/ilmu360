<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * The Events feature owns occurrence submission moderation and its side effects.
 * All external consumers supply scalar identifiers, never mutable models.
 */
interface OccurrenceSubmissionReviews
{
    public function approve(string $submissionId, string $moderatorType, string $moderatorId, ?string $note = null): void;

    public function reject(string $submissionId, string $moderatorType, string $moderatorId, string $reason, string $note): void;
}
