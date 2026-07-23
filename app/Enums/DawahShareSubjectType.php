<?php

declare(strict_types=1);

namespace App\Enums;

enum DawahShareSubjectType: string
{
    case Event = 'event';
    case Institution = 'institution';
    case Person = 'person';
    case Series = 'series';
    case Reference = 'reference';
    case Search = 'search';
    case Page = 'page';
}
