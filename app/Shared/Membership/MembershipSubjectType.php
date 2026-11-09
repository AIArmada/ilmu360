<?php

declare(strict_types=1);

namespace App\Shared\Membership;

enum MembershipSubjectType: string
{
    case Institution = 'institution';
    case Person = 'person';
    case Event = 'event';
    case Reference = 'reference';
    case Organization = 'organization';
}
