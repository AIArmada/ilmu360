<?php

declare(strict_types=1);

namespace App\Features\Identity\Api;

enum SignupAttributionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
}
