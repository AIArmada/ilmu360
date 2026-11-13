<?php

declare(strict_types=1);

namespace App\Features\Communications\Api;

enum NotificationRuleScope: string
{
    case Family = 'family';
    case Trigger = 'trigger';
}
