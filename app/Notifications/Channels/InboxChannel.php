<?php

namespace App\Notifications\Channels;

use AIArmada\Communications\Actions\DispatchInboxNotificationAction;
use AIArmada\Communications\Models\NotificationInbox;
use App\Notifications\NotificationCenterMessage;
use App\Support\Communications\EnumMapper;
use Illuminate\Notifications\Notification;

class InboxChannel
{
    public function __construct(
        private readonly DispatchInboxNotificationAction $dispatchAction,
    ) {}

    public function send(object $notifiable, Notification $notification): ?NotificationInbox
    {
        if (! $notification instanceof NotificationCenterMessage) {
            return null;
        }

        $family = EnumMapper::toPkgFamily($notification->family);
        $trigger = EnumMapper::toPkgTrigger($notification->trigger);
        $priority = EnumMapper::toPkgPriority($notification->priority);

        return $this->dispatchAction->handle(
            recipient: $notifiable,
            title: $notification->titleFor($notifiable),
            body: $notification->bodyFor($notifiable),
            family: $family,
            priority: $priority,
            trigger: $trigger,
            data: [
                'action_url' => $notification->actionUrl,
                'entity_type' => $notification->entityType,
                'entity_id' => $notification->entityId,
                'channels_attempted' => $notification->channelsAttempted,
                'meta' => $notification->meta,
                'occurred_at' => $notification->occurredAt?->toIso8601String(),
            ],
        );
    }
}
