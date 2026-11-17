<?php

declare(strict_types=1);

namespace App\Shared\Events;

/** Deliver an occurrence review notification after the moderation transaction. */
interface OccurrenceReviewNotifier
{
    public function notify(
        string $submissionId,
        string $eventId,
        string $occurrenceId,
        bool $approved,
        ?string $reason,
        ?string $note,
    ): void;
}
