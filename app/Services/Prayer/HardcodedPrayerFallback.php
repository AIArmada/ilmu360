<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use App\Enums\EventPrayerTime;

/**
 * Last-resort prayer clocks when no provider data is available.
 *
 * These are rough display estimates, never exact prayer times. Every consumer
 * (submit policy, admin mapper, advanced forms) reads this map so the values
 * cannot drift apart. Resolutions sourced here are tagged with SOURCE.
 */
final class HardcodedPrayerFallback
{
    public const string SOURCE = 'hardcoded:v1';

    /**
     * Fallback clock when no mapping exists (matches the SelepasMaghrib estimate).
     */
    public const string DEFAULT_CLOCK = '20:00';

    /**
     * @var array<string, string> EventPrayerTime value => local clock (H:i).
     */
    public const array MAP = [
        EventPrayerTime::SebelumSubuh->value => '06:15',
        EventPrayerTime::SelepasSubuh->value => '06:30',
        EventPrayerTime::SelepasZuhur->value => '13:30',
        EventPrayerTime::SebelumJumaat->value => '13:45',
        EventPrayerTime::SelepasJumaat->value => '14:00',
        EventPrayerTime::SelepasAsar->value => '17:00',
        EventPrayerTime::SebelumMaghrib->value => '19:45',
        EventPrayerTime::SelepasMaghrib->value => '20:00',
        EventPrayerTime::SelepasIsyak->value => '21:30',
        EventPrayerTime::SelepasTarawih->value => '22:30',
    ];

    /**
     * Estimate clock (H:i) for a prayer timing, or null when unmapped.
     */
    public static function clockFor(EventPrayerTime|string $prayerTime): ?string
    {
        $key = $prayerTime instanceof EventPrayerTime ? $prayerTime->value : $prayerTime;

        return self::MAP[$key] ?? null;
    }
}
