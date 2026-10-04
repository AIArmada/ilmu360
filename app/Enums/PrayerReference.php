<?php

namespace App\Enums;

enum PrayerReference: string
{
    case Fajr = 'fajr';
    case Dhuhr = 'dhuhr';
    case Asr = 'asr';
    case Maghrib = 'maghrib';
    case Isha = 'isha';
    case FridayPrayer = 'friday_prayer';

    public function label(): string
    {
        $key = "prayer.reference.{$this->value}";
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return match ($this) {
            self::Fajr => 'Subuh',
            self::Dhuhr => 'Zuhur',
            self::Asr => 'Asar',
            self::Maghrib => 'Maghrib',
            self::Isha => 'Isyak',
            self::FridayPrayer => 'Jumaat',
        };
    }

    /**
     * Normalized provider DTO key. Jumaat has no published table; it reads Dhuhr.
     */
    public function dtoKey(): string
    {
        return match ($this) {
            self::FridayPrayer => 'dhuhr',
            default => $this->value,
        };
    }

    /**
     * Get the Aladhan API field name for this prayer.
     */
    public function aladhanKey(): string
    {
        return match ($this) {
            self::Fajr => 'Fajr',
            self::Dhuhr, self::FridayPrayer => 'Dhuhr',
            self::Asr => 'Asr',
            self::Maghrib => 'Maghrib',
            self::Isha => 'Isha',
        };
    }
}
