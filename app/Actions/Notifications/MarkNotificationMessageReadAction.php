<?php

namespace App\Actions\Notifications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Models\NotificationInbox;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkNotificationMessageReadAction
{
    use AsAction;

    public function __construct(
        private ProductSignalsService $productSignalsService,
    ) {}

    public function handle(User $user, string $messageId, ?Request $request = null): NotificationInbox
    {
        OwnerContext::setForRequest(null);

        $message = $user->notificationInboxes()
            ->whereNull('archived_at')
            ->whereKey($messageId)
            ->firstOrFail();

        $wasUnread = $message->read_at === null;

        if ($wasUnread) {
            $user->markAsRead($messageId);
        }

        $freshMessage = $message->fresh() ?? $message;

        if ($wasUnread) {
            $this->productSignalsService->recordNotificationRead($freshMessage, $user, $request ?? request());
        }

        return $freshMessage;
    }
}
