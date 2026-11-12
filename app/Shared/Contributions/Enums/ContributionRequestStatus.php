<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Enums;

enum ContributionRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
