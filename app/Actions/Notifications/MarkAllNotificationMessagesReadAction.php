<?php

namespace App\Actions\Notifications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Services\NotificationInboxService;
use App\Models\User;
use App\Shared\Signals\SignalRecorder;
use App\Support\Communications\AccountCommunications;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkAllNotificationMessagesReadAction
{
    use AsAction;

    public function __construct(
        private SignalRecorder $signals,
        private NotificationInboxService $notificationInboxService,
        private AccountCommunications $communications,
    ) {}

    public function handle(User $user, ?Request $request = null): int
    {
        OwnerContext::setForRequest(null);

        $unreadMessages = $this->communications->inboxQuery((string) $user->getKey())
            ->whereNull('archived_at')
            ->whereNull('read_at');

        $updated = $unreadMessages->count();

        if ($updated > 0) {
            $this->notificationInboxService->markAllAsRead(
                $this->communications->inboxRelationFor($user)->whereNull('archived_at'),
            );
        }

        if ($updated > 0) {
            $this->signals->record(
                eventName: 'notification.read_all',
                eventCategory: 'notification',
                actorId: (string) $user->getKey(),
                properties: [
                    'updated_count' => $updated,
                ],
                request: $request ?? request(),
            );
        }

        return $updated;
    }
}
