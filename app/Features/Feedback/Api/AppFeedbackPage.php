<?php

declare(strict_types=1);

namespace App\Features\Feedback\Api;

/** Immutable page metadata for reporter feedback; UI owns its paginator. */
final readonly class AppFeedbackPage
{
    /** @param list<AppFeedbackSnapshot> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
    ) {}
}
