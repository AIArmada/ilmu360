<?php

declare(strict_types=1);

namespace App\Services\Prayer;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides whether a Gregorian day is a Tarawih evening.
 *
 * V2.4 rule: the announced-date config list (official declarations) is
 * PRIMARY and exact — no tolerance; windows store Tarawih evenings,
 * one day before the corresponding fasting dates. The tabular (Kuwaiti
 * arithmetic, moment-hijri-equivalent) estimate covers only days no
 * announced window governs, widened by ±1 day of fasting tolerance
 * since tabular math can miss a sighted boundary by a day.
 * Declarations are scoped per country: different authorities can
 * announce different boundaries, and countries without a declaration
 * use the estimate. Pure date math: no HTTP, safe on the submit path.
 */
final class RamadanGate
{
    /**
     * Days from an announced window inside which official data governs.
     * Ramadan seasons sit ~354 days apart, so 150 days cannot straddle two.
     */
    private const int ANNOUNCED_SEASON_RADIUS_DAYS = 150;

    public function isRamadan(CarbonInterface|string $date, ?string $timezone = null, ?string $countryCode = null): bool
    {
        $timezone ??= (string) config('app.timezone', 'UTC');

        // Date-only strings parse IN the target timezone: parsing in the
        // app timezone first can shift the calendar day across the
        // conversion. Instants convert normally.
        $day = $date instanceof CarbonInterface
            ? CarbonImmutable::parse($date)->setTimezone($timezone)->startOfDay()
            : CarbonImmutable::parse($date, $timezone)->startOfDay();

        $windows = $this->announcedWindows($timezone, $countryCode);

        foreach ($windows as [$start, $end]) {
            if ($day->between($start, $end)) {
                return true;
            }
        }

        foreach ($windows as [$start, $end]) {
            // Official data governs its own season exactly; a day far from
            // every announced window (e.g. a December straddler whose year
            // entry describes the January Ramadan) falls to the estimate.
            if ($day->diffInDays($start, true) <= self::ANNOUNCED_SEASON_RADIUS_DAYS
                || $day->diffInDays($end, true) <= self::ANNOUNCED_SEASON_RADIUS_DAYS) {
                return false;
            }
        }

        return $this->isTabularRamadan($day);
    }

    /**
     * Gregorian date → tabular Hijri parts (Kuwaiti arithmetic calendar,
     * the same family as moment-hijri). Floor division throughout: the
     * classic algorithm assumes floored quotients for negative numerators.
     *
     * @return array{year: int, month: int, day: int}
     */
    public function hijriParts(int $year, int $month, int $day): array
    {
        $jd = $this->gregorianToJd($year, $month, $day);

        $l = $jd - 1948440 + 10632;
        $n = $this->floorDiv($l - 1, 10631);
        $l = $l - 10631 * $n + 354;
        $j = $this->floorDiv(10985 - $l, 5316) * $this->floorDiv(50 * $l, 17719)
            + $this->floorDiv($l, 5670) * $this->floorDiv(43 * $l, 15238);
        $l = $l - $this->floorDiv(30 - $j, 15) * $this->floorDiv(17719 * $j, 50)
            - $this->floorDiv($j, 16) * $this->floorDiv(15238 * $j, 43) + 29;
        $m = $this->floorDiv(24 * $l, 709);

        return [
            'year' => 30 * $n + $j - 30,
            'month' => $m,
            'day' => $l - $this->floorDiv(709 * $m, 24),
        ];
    }

    /**
     * ±1-day fasting tolerance by construction, answered in evenings: the
     * evening of D precedes fasting day D+1, so the candidate shifts one
     * day ahead before the yesterday/today/tomorrow probe. Fasting F..E
     * therefore admits evenings F-2..E — never E+1, whose fast is over.
     */
    private function isTabularRamadan(CarbonImmutable $day): bool
    {
        foreach ([0, 1, 2] as $offset) {
            $probe = $day->addDays($offset);
            $hijri = $this->hijriParts($probe->year, $probe->month, $probe->day);

            if ($hijri['month'] === 9) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declarations are scoped by country (or authority): a country without
     * an applicable declaration — and calls without a country identity —
     * fall through to the tabular estimate rather than borrowing another
     * country's exact boundaries.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function announcedWindows(string $timezone, ?string $countryCode): array
    {
        $countryCode = is_string($countryCode) ? strtoupper(trim($countryCode)) : '';

        if ($countryCode === '') {
            return [];
        }

        $announced = config("prayer.ramadan.announced.{$countryCode}", []);
        $windows = [];

        if (! is_array($announced)) {
            return [];
        }

        foreach ($announced as $year => $period) {
            $start = $period['start'] ?? null;
            $end = $period['end'] ?? null;

            if (! is_string($start) || ! is_string($end)
                || preg_match('/^\d{2}-\d{2}$/', $start) !== 1
                || preg_match('/^\d{2}-\d{2}$/', $end) !== 1) {
                continue;
            }

            $windowStart = CarbonImmutable::parse("{$year}-{$start}", $timezone)->startOfDay();
            $windowEnd = CarbonImmutable::parse("{$year}-{$end}", $timezone)->endOfDay();

            if ($windowEnd->lessThan($windowStart)) {
                // December-straddling Ramadan (e.g. 2030): the end belongs
                // to the following year rather than silently never matching.
                $windowEnd = $windowEnd->addYear();
            }

            $windows[] = [$windowStart, $windowEnd];
        }

        return $windows;
    }

    private function gregorianToJd(int $year, int $month, int $day): int
    {
        if ($month < 3) {
            $year--;
            $month += 12;
        }

        $a = $this->floorDiv($year, 100);

        return 2 - $a + $this->floorDiv($a, 4)
            + (int) (365.25 * ($year + 4716))
            + (int) (30.6001 * ($month + 1))
            + $day - 1524;
    }

    private function floorDiv(int $a, int $b): int
    {
        return (int) floor($a / $b);
    }
}
