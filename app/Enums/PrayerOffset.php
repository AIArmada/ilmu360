<?php

namespace App\Enums;

enum PrayerOffset: string
{
    case Before30 = 'before_30';
    case Before15 = 'before_15';
    case Immediately = 'immediately';
    case After15 = 'after_15';
    case After30 = 'after_30';
    case After45 = 'after_45';
    case After60 = 'after_60';

    public function label(): string
    {
        $key = "prayer.offset.{$this->value}";
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        return match ($this) {
            self::Before30 => '30 minit sebelum',
            self::Before15 => '15 minit sebelum',
            self::Immediately => 'Sejurus selepas',
            self::After15 => '15 minit selepas',
            self::After30 => '30 minit selepas',
            self::After45 => '45 minit selepas',
            self::After60 => '1 jam selepas',
        };
    }

    /**
     * Get the offset in minutes (negative = before, positive = after).
     */
    public function minutes(): int
    {
        return match ($this) {
            self::Before30 => -30,
            self::Before15 => -15,
            self::Immediately => 5, // 5 minutes after to allow for prayer completion
            self::After15 => 15,
            self::After30 => 30,
            self::After45 => 45,
            self::After60 => 60,
        };
    }

    /**
     * Create display text for the timing.
     */
    public function displayText(PrayerReference $prayer): string
    {
        $prayerLabel = $prayer->label();
        $key = "prayer.display.{$this->value}";
        $translated = __($key, ['prayer' => $prayerLabel]);

        if ($translated !== $key) {
            return $translated;
        }

        return match ($this) {
            self::Before30 => "30 minit sebelum {$prayerLabel}",
            self::Before15 => "15 minit sebelum {$prayerLabel}",
            self::Immediately => "Selepas {$prayerLabel}",
            self::After15 => "15 minit selepas {$prayerLabel}",
            self::After30 => "30 minit selepas {$prayerLabel}",
            self::After45 => "45 minit selepas {$prayerLabel}",
            self::After60 => "1 jam selepas {$prayerLabel}",
        };
    }
}
