<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

use App\Shared\Auth\AccountActor;

interface NotificationDispatch
{
    public function sendInAppToAccount(AccountActor $recipient, InAppNotificationPayload $payload): void;
}
