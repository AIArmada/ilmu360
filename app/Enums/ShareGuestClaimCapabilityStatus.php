<?php

declare(strict_types=1);

namespace App\Enums;

enum ShareGuestClaimCapabilityStatus: string
{
    case Active = 'active';
    case Consumed = 'consumed';
    case Revoked = 'revoked';
}
