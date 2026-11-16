<?php

declare(strict_types=1);

namespace App\Features\Communications\Services;

use AIArmada\Communications\Contracts\CommunicationManager;
use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\NotificationDispatch;
use App\Features\Communications\Notifications\InAppNotification;
use App\Shared\Auth\AccountActor;

final readonly class NotificationDispatchService implements NotificationDispatch
{
    public function __construct(
        private CommunicationManager $communications,
    ) {}

    public function sendInAppToAccount(AccountActor $recipient, InAppNotificationPayload $payload): void
    {
        $this->communications->notify($recipient, new InAppNotification($payload));
    }
}
