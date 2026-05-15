<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationRuleScope: string
{
    case Family = 'family';
    case Trigger = 'trigger';
}
