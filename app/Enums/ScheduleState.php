<?php

namespace App\Enums;

enum ScheduleState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Postponed = 'postponed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Paused => __('Paused'),
            self::Postponed => __('Postponed'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
