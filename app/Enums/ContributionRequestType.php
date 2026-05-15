<?php

declare(strict_types=1);

namespace App\Enums;

enum ContributionRequestType: string
{
    case Create = 'create';
    case Update = 'update';
}
