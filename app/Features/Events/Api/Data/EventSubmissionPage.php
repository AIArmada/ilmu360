<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Data;

/** Immutable page metadata for submission cards; UI owns its paginator. */
final readonly class EventSubmissionPage
{
    /** @param list<EventSubmissionCard> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
    ) {}
}
