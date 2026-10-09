<?php

namespace App\Notifications\Channels;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Actions\DispatchInboxNotificationAction;
use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Models\NotificationInbox;
use App\Notifications\InAppNotification;
use App\Support\Communications\EnumMapper;
use App\Support\Communications\GuardedDeliverySend;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class InboxChannel
{
    public function __construct(
        private readonly DispatchInboxNotificationAction $dispatchAction,
        private readonly CommunicationRecorder $recorder,
    ) {}

    public function send(object $notifiable, Notification $notification): ?NotificationInbox
    {
        if (! $notification instanceof InAppNotification) {
            return null;
        }

        $deliveryId = GuardedDeliverySend::deliveryIdFor($notification, static::class);

        if ($deliveryId === null) {
            return $this->write($notifiable, $notification);
        }

        $communicationId = GuardedDeliverySend::communicationIdOf($notification);

        return DB::transaction(function () use ($notifiable, $notification, $deliveryId, $communicationId): ?NotificationInbox {
            $delivery = GuardedDeliverySend::claim($deliveryId, $communicationId);

            if ($delivery === null || $communicationId === null) {
                GuardedDeliverySend::detach($notification, static::class);

                return null;
            }

            $row = $this->write($notifiable, $notification);

            $this->recorder->markSent($communicationId, (string) $delivery->getKey(), []);

            return $row;
        });
    }

    private function write(object $notifiable, InAppNotification $notification): NotificationInbox
    {
        $family = EnumMapper::toPkgFamily($notification->family);
        $trigger = EnumMapper::toPkgTrigger($notification->trigger);
        $priority = EnumMapper::toPkgPriority($notification->priority);

        return OwnerContext::withOwner(null, fn (): NotificationInbox => $this->dispatchAction->handle(
            recipient: $notifiable,
            title: $notification->title,
            body: $notification->body,
            family: $family,
            priority: $priority,
            trigger: $trigger,
            data: [
                'action_url' => $notification->actionUrl,
                'entity_type' => $notification->entityType,
                'entity_id' => $notification->entityId,
                'meta' => $notification->meta,
                'occurred_at' => $notification->occurredAt?->toIso8601String(),
                'pending_notification_id' => $notification->pendingNotificationId,
            ],
        ));
    }
}
