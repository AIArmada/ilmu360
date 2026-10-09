<?php

declare(strict_types=1);

namespace App\Enums;

enum ShareGuestClaimStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
