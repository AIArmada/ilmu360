<?php

namespace App\Features\Communications\EntryPoints\Channels;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Actions\DispatchInboxNotificationAction;
use AIArmada\Communications\Contracts\CommunicationRecorder;
use AIArmada\Communications\Models\CommunicationDelivery;
use AIArmada\Communications\Models\NotificationInbox;
use App\Features\Communications\Api\EnumMapper;
use App\Features\Communications\Api\InAppNotificationMessage;
use App\Features\Communications\Api\NotificationTransport;
use App\Features\Communications\Support\Delivery\GuardedDeliverySend;
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
        if (! $notification instanceof InAppNotificationMessage) {
            return null;
        }

        $deliveryId = GuardedDeliverySend::deliveryIdFor($notification, NotificationTransport::Inbox->value);

        if ($deliveryId === null) {
            return $this->write($notifiable, $notification);
        }

        $communicationId = GuardedDeliverySend::communicationIdOf($notification);

        return DB::transaction(function () use ($notifiable, $notification, $deliveryId, $communicationId): ?NotificationInbox {
            $delivery = GuardedDeliverySend::claim($deliveryId, $communicationId);

            if (! $delivery instanceof CommunicationDelivery || $communicationId === null) {
                GuardedDeliverySend::detach($notification, NotificationTransport::Inbox->value);

                return null;
            }

            $row = $this->write($notifiable, $notification);

            $this->recorder->markSent($communicationId, (string) $delivery->getKey(), []);

            return $row;
        });
    }

    private function write(object $notifiable, InAppNotificationMessage $notification): NotificationInbox
    {
        $payload = $notification->inAppPayload();
        $family = EnumMapper::toPkgFamily($payload->family);
        $trigger = EnumMapper::toPkgTrigger($payload->trigger);
        $priority = EnumMapper::toPkgPriority($payload->priority);

        return OwnerContext::withOwner(null, fn (): NotificationInbox => $this->dispatchAction->handle(
            recipient: $notifiable,
            title: $payload->title,
            body: $payload->body,
            family: $family,
            priority: $priority,
            trigger: $trigger,
            data: [
                'action_url' => $payload->actionUrl,
                'entity_type' => $payload->entityType,
                'entity_id' => $payload->entityId,
                'meta' => $payload->meta,
                'occurred_at' => $payload->occurredAt,
                'pending_notification_id' => $payload->pendingNotificationId,
            ],
        ));
    }
}
