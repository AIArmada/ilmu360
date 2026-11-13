<?php

namespace App\Notifications;

use App\Features\Communications\Api\InAppNotificationMessage;
use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\NotificationFamily;
use App\Features\Communications\Api\NotificationPriority;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Communications\Api\NotificationTransport;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;

class InAppNotification extends Notification implements InAppNotificationMessage
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
        public ?CarbonInterface $occurredAt = null,
        public array $meta = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return [NotificationTransport::Inbox->value];
    }

    public function inAppPayload(): InAppNotificationPayload
    {
        return new InAppNotificationPayload(
            pendingNotificationId: $this->pendingNotificationId,
            family: $this->family,
            trigger: $this->trigger,
            priority: $this->priority,
            title: $this->title,
            body: $this->body,
            actionUrl: $this->actionUrl,
            entityType: $this->entityType,
            entityId: $this->entityId,
            occurredAt: $this->occurredAt?->toIso8601String(),
            meta: $this->meta,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return $this->inAppPayload()->toArray();
    }
}
