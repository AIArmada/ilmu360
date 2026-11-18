<?php

declare(strict_types=1);

namespace App\Platform\Api\Resources;

enum ResourceSurface: string
{
    case Admin = 'admin';
    case Member = 'member';
}
