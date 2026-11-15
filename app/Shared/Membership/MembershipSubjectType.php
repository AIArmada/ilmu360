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

    public function label(): string
    {
        return match ($this) {
            self::Institution => __('Institution'),
            self::Person => __('Person'),
            self::Event => __('Event'),
            self::Reference => __('Reference'),
            self::Organization => __('Organization'),
        };
    }
}
