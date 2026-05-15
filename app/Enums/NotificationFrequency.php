<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationFrequency: string
{
    case Off = 'off';
    case Instant = 'instant';
    case Daily = 'daily';
    case Weekly = 'weekly';
}
