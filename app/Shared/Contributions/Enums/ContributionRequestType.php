<?php

declare(strict_types=1);

namespace App\Shared\Contributions\Enums;

enum ContributionRequestType: string
{
    case Create = 'create';
    case Update = 'update';
}
