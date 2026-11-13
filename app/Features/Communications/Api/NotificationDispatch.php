<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

interface NotificationDispatch
{
    public function sendInAppToAccount(string $accountId, InAppNotificationPayload $payload): void;
}
