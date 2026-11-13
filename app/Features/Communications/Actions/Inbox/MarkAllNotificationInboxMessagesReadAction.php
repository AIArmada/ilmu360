<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions\Inbox;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Communications\Services\NotificationInboxService;
use App\Shared\Auth\UserMorphType;
use App\Shared\Signals\SignalRecorder;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkAllNotificationInboxMessagesReadAction
{
    use AsAction;

    public function __construct(
        private ResolveNotificationInboxRecipientAction $resolveRecipient,
        private NotificationInboxService $notificationInboxService,
        private SignalRecorder $signals,
        private UserMorphType $userMorphType,
    ) {}

    public function handle(string $accountId): int
    {
        OwnerContext::setForRequest(null);

        $recipient = $this->resolveRecipient->handle($accountId);
        $unreadMessages = NotificationInbox::query()
            ->where('recipient_type', $this->userMorphType->morphClass())
            ->where('recipient_id', $accountId)
            ->whereNull('archived_at')
            ->whereNull('read_at');

        $updated = $unreadMessages->count();

        if ($updated > 0) {
            $this->notificationInboxService->markAllAsRead(
                $recipient->morphMany(NotificationInbox::class, 'recipient')->whereNull('archived_at'),
            );
            $this->signals->record(
                eventName: 'notification.read_all',
                eventCategory: 'notification',
                actorId: $accountId,
                properties: [
                    'updated_count' => $updated,
                ],
                request: request(),
            );
        }

        return $updated;
    }
}
