<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class MarkAllNotificationMessagesReadAction
{
    use AsAction;

    public function __construct(
        private ProductSignalsService $productSignalsService,
    ) {}

    public function handle(User $user, ?Request $request = null): int
    {
        $updated = $user
            ->notificationMessages()
            ->visibleInInbox()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $this->productSignalsService->recordNotificationsReadAll($user, $updated, $request ?? request());

        return $updated;
    }
}
