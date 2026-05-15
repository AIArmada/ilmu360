<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationDestinationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Revoked = 'revoked';
}
