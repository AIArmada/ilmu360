<?php

namespace App\Features\Communications\Api;

interface InAppNotificationMessage
{
    public function inAppPayload(): InAppNotificationPayload;
}
