<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Features\Communications\Api\NotificationDispatchData;
use App\Features\Events\Notifications\EventNotificationService;

final class SavedSearchNotificationRecorder extends EventNotificationService
{
    /** @var list<array{recipientId: string, data: NotificationDispatchData}> */
    public array $recordedNotifications = [];

    protected function dispatchForUsers(iterable $users, callable $builder): void
    {
        foreach ($users as $user) {
            $this->recordedNotifications[] = [
                'recipientId' => (string) $user->getKey(),
                'data' => $builder($user),
            ];
        }
    }
}
