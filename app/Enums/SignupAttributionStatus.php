<?php

declare(strict_types=1);

namespace App\Enums;

enum SignupAttributionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
}
