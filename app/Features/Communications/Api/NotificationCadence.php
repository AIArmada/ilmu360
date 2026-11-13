<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

enum NotificationCadence: string
{
    case Off = 'off';
    case Instant = 'instant';
    case Daily = 'daily';
    case Weekly = 'weekly';
}
