<?php

declare(strict_types=1);

namespace App\Shared\Engagement;

enum EngagementSubjectType: string
{
    case Venue = 'venue';
    case Person = 'person';
    case Institution = 'institution';
    case Reference = 'reference';
    case Series = 'series';
}
