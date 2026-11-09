<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

enum PrayerType: string
{
    case Daily = 'daily';
    case Friday = 'friday';
}
