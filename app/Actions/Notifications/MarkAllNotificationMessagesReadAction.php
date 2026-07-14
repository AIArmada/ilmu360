<?php

namespace App\Actions\Notifications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Services\NotificationInboxService;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkAllNotificationMessagesReadAction
{
    use AsAction;

    public function __construct(
        private ProductSignalsService $productSignalsService,
        private NotificationInboxService $notificationInboxService,
    ) {}

    public function handle(User $user, ?Request $request = null): int
    {
        OwnerContext::setForRequest(null);

        $unreadMessages = $user
            ->notificationInboxes()
            ->whereNull('archived_at')
            ->whereNull('read_at');

        $updated = $unreadMessages->count();

        if ($updated > 0) {
            $this->notificationInboxService->markAllAsRead(
                $user->notificationInboxes()->whereNull('archived_at'),
            );
        }

        $this->productSignalsService->recordNotificationsReadAll($user, $updated, $request ?? request());

        return $updated;
    }
}
