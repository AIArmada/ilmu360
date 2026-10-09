<?php

declare(strict_types=1);

namespace App\Enums;

enum ShareEffectStatus: string
{
    case Pending = 'pending';
    case Leased = 'leased';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
