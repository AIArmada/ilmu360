<?php

declare(strict_types=1);

namespace App\Data\Events;

use App\Models\Event;
use App\Models\Institution;
use App\Models\User;

/**
 * Per-request submission scope for the event form schema.
 *
 * Resolved fresh on every request by the component guards; never
 * dehydrated, memoized across requests, or stored statically.
 */
final readonly class SubmitEventFormContext
{
    public function __construct(
        public ?Institution $scopedInstitution,
        public ?Event $eventContainer,
        public ?User $submitter,
        public ?string $requestedOccurrenceId,
    ) {}
}
