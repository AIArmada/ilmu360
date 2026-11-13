<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions\Inbox;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Communications\Services\NotificationInboxService;
use App\Features\Communications\Api\NotificationInboxMessageSnapshot;
use App\Features\Communications\Support\NotificationInbox\NotificationInboxMessageSnapshotFactory;
use App\Shared\Auth\UserMorphType;
use App\Shared\Signals\SignalRecorder;
use BackedEnum;
use Lorisleiva\Actions\Concerns\AsAction;
use UnitEnum;

final readonly class MarkNotificationInboxMessageReadAction
{
    use AsAction;

    public function __construct(
        private ResolveNotificationInboxRecipientAction $resolveRecipient,
        private NotificationInboxService $notificationInboxService,
        private NotificationInboxMessageSnapshotFactory $snapshots,
        private SignalRecorder $signals,
        private UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId, string $messageId): NotificationInboxMessageSnapshot
    {
        OwnerContext::setForRequest(null);

        $recipient = $this->resolveRecipient->handle($accountId);
        $message = NotificationInbox::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('archived_at')
            ->whereKey($messageId)
            ->firstOrFail();

        $wasUnread = $message->read_at === null;

        if ($wasUnread) {
            $this->notificationInboxService->markAsRead(
                $recipient->morphMany(NotificationInbox::class, 'recipient')->whereNull('archived_at'),
                $messageId,
            );
        }

        $freshMessage = $message->fresh() ?? $message;

        if ($wasUnread) {
            $this->signals->record(
                eventName: 'notification.read',
                eventCategory: 'notification',
                actorId: $accountId,
                properties: [
                    'notification_id' => (string) $freshMessage->getKey(),
                    'family' => $this->enumValue($freshMessage->family),
                    'trigger' => $this->enumValue($freshMessage->trigger),
                    'action_url' => $freshMessage->data['action_url'] ?? null,
                ],
                request: request(),
            );
        }

        return $this->snapshots->fromModel($freshMessage);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
