<?php

namespace App\Actions\Notifications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Communications\Services\NotificationInboxService;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use App\Support\Communications\AccountCommunications;
use BackedEnum;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use UnitEnum;

final readonly class MarkNotificationMessageReadAction
{
    use AsAction;

    public function __construct(
        private SignalRecorder $signals,
        private NotificationInboxService $notificationInboxService,
        private AccountCommunications $communications,
    ) {}

    public function handle(User $user, string $messageId, ?Request $request = null): NotificationInbox
    {
        OwnerContext::setForRequest(null);

        $message = $this->communications->inboxQuery((string) $user->getKey())
            ->whereNull('archived_at')
            ->whereKey($messageId)
            ->firstOrFail();

        $wasUnread = $message->read_at === null;

        if ($wasUnread) {
            $this->notificationInboxService->markAsRead(
                $this->communications->inboxRelationFor($user)->whereNull('archived_at'),
                $messageId,
            );
        }

        $freshMessage = $message->fresh() ?? $message;

        if ($wasUnread) {
            $this->signals->record(
                eventName: 'notification.read',
                eventCategory: 'notification',
                actorId: (string) $user->getKey(),
                properties: [
                    'notification_id' => (string) $freshMessage->getKey(),
                    'family' => $this->enumValue($freshMessage->family),
                    'trigger' => $this->enumValue($freshMessage->trigger),
                    'action_url' => $freshMessage->data['action_url'] ?? null,
                ],
                request: $request ?? request(),
            );
        }

        return $freshMessage;
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
