<?php

declare(strict_types=1);

namespace App\Features\Feedback\Api;

use Carbon\CarbonImmutable;

/** Immutable application-feedback snapshot; no model or relation escapes Feedback. */
final readonly class AppFeedbackSnapshot
{
    public function __construct(
        public string $id,
        public string $category,
        public string $categoryLabel,
        public string $categoryColor,
        public string $status,
        public string $statusLabel,
        public string $statusColor,
        public string $title,
        public string $description,
        public ?string $pageUrl,
        public ?string $resolutionNote,
        public int $visibleMessagesCount,
        public ?CarbonImmutable $createdAt,
        public string $threadUrl,
    ) {}
}
