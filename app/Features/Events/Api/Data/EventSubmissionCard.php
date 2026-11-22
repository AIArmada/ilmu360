<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

/** Immutable event-submission card; no model or relation escapes Events. */
final readonly class EventSubmissionCard
{
    /** @param array<string, string|null> $presentation
     *  @param list<string> $details
     */
    public function __construct(
        public string $id,
        public string $eventId,
        public ?string $eventTitle,
        public ?string $eventStatus,
        public ?string $eventUrl,
        public ?string $occurrenceId,
        public ?string $createdAt,
        public ?string $createdAtHuman,
        public ?string $notes,
        public array $presentation,
        public array $details,
    ) {}
}
