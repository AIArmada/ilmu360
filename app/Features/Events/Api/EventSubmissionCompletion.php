<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Canonical completion of a persisted public event submission.
 *
 * Source state transitions stay in Events, while transport-specific request
 * attribution and delivery are processed by its caller in the same transaction.
 */
interface EventSubmissionCompletion
{
    /** @param array<string, mixed> $state */
    public function complete(
        string $eventId,
        string $submissionId,
        array $state,
        ?string $submitterId,
        bool $sessionSubmission,
        bool $autoApproved,
        bool $parentCreated,
    ): void;
}
