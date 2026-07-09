<?php

namespace App\Notifications;

use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;

class InAppNotification extends Notification
{
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
        public ?CarbonInterface $occurredAt = null,
        public array $meta = [],
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
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
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'meta' => $this->meta,
            'pending_notification_id' => $this->pendingNotificationId,
        ];
    }
}
