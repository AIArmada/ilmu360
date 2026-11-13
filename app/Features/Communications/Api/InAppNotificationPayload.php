<?php

namespace App\Features\Communications\Api;

final readonly class InAppNotificationPayload
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $pendingNotificationId,
        public NotificationFamily $family,
        public NotificationTrigger $trigger,
        public NotificationPriority $priority,
        public string $title,
        public string $body,
        public ?string $actionUrl = null,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public ?string $occurredAt = null,
        public array $meta = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'family' => $this->family->value,
            'trigger' => $this->trigger->value,
            'priority' => $this->priority->value,
            'title' => $this->title,
            'body' => $this->body,
            'action_url' => $this->actionUrl,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'occurred_at' => $this->occurredAt,
            'meta' => $this->meta,
            'pending_notification_id' => $this->pendingNotificationId,
        ];
    }
}
