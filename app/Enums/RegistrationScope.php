<?php

namespace App\Enums;

/**
 * Registration scope used by the advanced parent-program flow.
 *
 * This is distinct from AIArmada\Events\Enums\RegistrationMode, which
 * describes whether registration is required, optional, or open-door.
 */
enum RegistrationScope: string
{
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Event => __('Whole Event'),
        };
    }
}
