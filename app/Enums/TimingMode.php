<?php

namespace App\Enums;

enum TimingMode: string
{
    case Absolute = 'absolute';
    case PrayerRelative = 'prayer_relative';

    public function label(): string
    {
        return match ($this) {
            self::Absolute => __('Exact Time'),
            self::PrayerRelative => __('Prayer Time'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Absolute => __('Set a specific time (e.g., 10:00 AM)'),
            self::PrayerRelative => __('Relative to prayer times (e.g., after Maghrib)'),
        };
    }
}
