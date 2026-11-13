<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

final readonly class NotificationInboxMessageSnapshot
{
    /**
     * @param  list<string>  $channelsAttempted
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $family,
        public string $trigger,
        public string $title,
        public ?string $body,
        public ?string $actionUrl,
        public ?string $entityType,
        public ?string $entityId,
        public string $priority,
        public ?string $occurredAt,
        public ?string $readAt,
        public array $channelsAttempted,
        public array $meta,
        public ?string $createdAt,
    ) {}
}
