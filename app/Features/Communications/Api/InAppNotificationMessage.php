<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

interface InAppNotificationMessage
{
    public function inAppPayload(): InAppNotificationPayload;
}
