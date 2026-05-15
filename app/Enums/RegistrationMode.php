<?php

namespace App\Enums;

enum RegistrationMode: string
{
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Event => __('Whole Event'),
        };
    }
}
