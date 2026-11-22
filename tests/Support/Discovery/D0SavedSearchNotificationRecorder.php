<?php

declare(strict_types=1);

namespace Tests\Support\Discovery;

use App\Features\Communications\Api\NotificationDispatchData;
use App\Features\Events\Notifications\EventNotificationService;

final class D0SavedSearchNotificationRecorder extends EventNotificationService
{
    /** @var list<NotificationDispatchData> */
    public array $recorded = [];

    protected function dispatchForUsers(iterable $users, callable $builder): void
    {
        foreach ($users as $user) {
            $this->recorded[] = $builder($user);
        }
    }
}
