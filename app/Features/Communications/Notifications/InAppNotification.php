<?php

namespace App\Features\Communications\Notifications;

use App\Features\Communications\Api\InAppNotificationMessage;
use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\NotificationTransport;
use Illuminate\Notifications\Notification;

final class InAppNotification extends Notification implements InAppNotificationMessage
{
    public function __construct(
        private readonly InAppNotificationPayload $payload,
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
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return $this->inAppPayload()->toArray();
    }
}
