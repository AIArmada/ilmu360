<?php

namespace App\Actions\Notifications;

use App\Models\NotificationMessage;
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

    public function handle(User $user, string $messageId, ?Request $request = null): NotificationMessage
    {
        $message = $user->notificationMessages()
            ->visibleInInbox()
            ->whereKey($messageId)
            ->firstOrFail();

        $wasUnread = $message->read_at === null;

        $message->markAsRead();

        $freshMessage = $message->fresh() ?? $message;

        if ($wasUnread) {
            $this->productSignalsService->recordNotificationRead($freshMessage, $user, $request ?? request());
        }

        return $freshMessage;
    }
}
