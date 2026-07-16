<?php

declare(strict_types=1);

namespace App\Enums;

enum EventEscalationType: string
{
    case ModeratorSla = 'moderator_sla';
    case SuperAdminSla = 'super_admin_sla';
    case Imminent = 'imminent';
    case Priority = 'priority';
}
