<?php

declare(strict_types=1);

namespace App\Support\Prayer;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses provider clock strings into UTC anchors.
 *
 * Shared by the Jakim (V1), Ummah, and Aladhan providers so clock shape
 * and hour/minute ranges validate in exactly one place. Returns null for
 * anything unparseable; callers map that to their missing-field error.
 * Absolute timestamps (Jakim V2 epochs arrive as instants; Ummah ISO
 * text parses here) additionally validate against the row's prayer day
 * so a correct date echo can never smuggle in a wrong-day anchor.
 */
final class PrayerClock
{
    public static function parseWallClock(mixed $clock, string $date, string $timezone): ?CarbonImmutable
    {
        if (! is_string($clock) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($clock), $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        try {
            return CarbonImmutable::parse($date, $timezone)->setTime($hour, $minute)->setTimezone('UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parses one absolute provider datetime into a UTC anchor.
     *
     * Syntax is strict — full date plus time with an optional offset —
     * so relative text (`tomorrow`, `+1 day`) never sneaks through
     * Carbon's parser. An explicit offset wins; the fallback timezone
     * applies only when the provider omits it. The instant's LOCAL date
     * must be the row's prayer day: UTC crossover is fine, a wrong-day
     * stamp is not. Isha alone may land on the next local day
     * (post-midnight in extreme latitudes).
     */
    public static function parseAbsoluteDatetime(mixed $iso, string $date, string $timezone, bool $allowNextDay = false): ?CarbonImmutable
    {
        if (! is_string($iso)) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', trim($iso), $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (! checkdate($month, $day, $year)
            || (int) $matches[4] > 23
            || (int) $matches[5] > 59
            || ((int) ($matches[6] ?? 0)) > 59) {
            return null;
        }

        try {
            $instant = CarbonImmutable::parse(trim($iso), $timezone);
        } catch (Throwable) {
            return null;
        }

        return self::isOnPrayerDay($instant, $date, $timezone, $allowNextDay)
            ? $instant->setTimezone('UTC')
            : null;
    }

    /**
     * True when the instant's LOCAL calendar date is the prayer day, so
     * legitimate UTC crossover passes while wrong-day stamps fail. Isha
     * alone may spill into the next local day's pre-dawn hours — a D+1
     * evening stamp belongs to the following prayer day, not this row.
     */
    public static function isOnPrayerDay(CarbonImmutable $instant, string $date, string $timezone, bool $allowNextDay = false): bool
    {
        try {
            $local = $instant->setTimezone($timezone);

            if ($local->format('Y-m-d') === $date) {
                return true;
            }

            return $allowNextDay
                && $local->format('Y-m-d') === CarbonImmutable::parse($date, $timezone)->addDay()->format('Y-m-d')
                && (int) $local->format('H') < 6;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Row-level chronology: isha never precedes its own maghrib. An
     * absolute stamp from the previous night (D00:30 against a D19:00
     * maghrib) passes same-day validation but belongs to the previous
     * prayer day. Clock-derived rows roll overnight isha before caching,
     * so a preceding isha is always corrupt. Missing anchors pass —
     * per-anchor presence validates separately.
     *
     * @param  array<string, CarbonImmutable>  $timesUtc
     */
    public static function isIshaAfterMaghrib(array $timesUtc): bool
    {
        $isha = $timesUtc['isha'] ?? null;
        $maghrib = $timesUtc['maghrib'] ?? null;

        if (! $isha instanceof CarbonImmutable || ! $maghrib instanceof CarbonImmutable) {
            return true;
        }

        return ! $isha->lessThan($maghrib);
    }

    /**
     * Final row gate mirroring the cache readers: every anchor on the
     * prayer day (isha alone may spill pre-dawn into D+1) and isha never
     * preceding its own maghrib. Providers apply this after rollover so
     * live preview can never serve a row cache-only submission rejects.
     *
     * @param  array<string, CarbonImmutable>  $timesUtc
     */
    public static function isRowOnPrayerDay(array $timesUtc, string $date, string $timezone): bool
    {
        foreach ($timesUtc as $key => $instant) {
            if (! $instant instanceof CarbonImmutable
                || ! self::isOnPrayerDay($instant, $date, $timezone, $key === 'isha')) {
                return false;
            }
        }

        return self::isIshaAfterMaghrib($timesUtc);
    }

    /**
     * Rolls a clock-derived isha past midnight using row chronology: an
     * isha before maghrib on the same attached day belongs to the next
     * local day. The rollover is calendar arithmetic in the provider
     * timezone — adding 24 hours to the UTC instant would shift the
     * wall clock across a DST transition. Callers apply this to
     * clock-attached isha only — day-validated absolute stamps keep
     * whatever date they carry.
     */
    public static function rollOvernightIsha(?CarbonImmutable $isha, ?CarbonImmutable $maghrib, string $timezone): ?CarbonImmutable
    {
        if (! $isha instanceof CarbonImmutable || ! $maghrib instanceof CarbonImmutable) {
            return $isha;
        }

        if (! $isha->lessThan($maghrib)) {
            return $isha;
        }

        try {
            $local = $isha->setTimezone($timezone);
            $nextDay = CarbonImmutable::parse($local->format('Y-m-d'), $timezone)->addDay();

            return $nextDay->setTime($local->hour, $local->minute, $local->second)->setTimezone('UTC');
        } catch (Throwable) {
            return $isha->addDay();
        }
    }
}
