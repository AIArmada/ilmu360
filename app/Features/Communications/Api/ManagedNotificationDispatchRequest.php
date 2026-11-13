<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

use Carbon\CarbonImmutable;

final readonly class ManagedNotificationDispatchRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $recipientId,
        public string $idempotencyKey,
        public string $category,
        public string $priority,
        public string $timezone,
        public array $metadata,
        public ManagedNotificationMessage $message,
        public ManagedNotificationMessage $redriveMessage,
        public CarbonImmutable $now,
        public int $stalledMinutes,
    ) {}
}
