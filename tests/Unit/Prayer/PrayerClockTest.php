<?php

use App\Support\Prayer\PrayerClock;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('parses wall clocks into UTC anchors', function () {
    $anchor = PrayerClock::parseWallClock('19:02', '2026-10-15', 'Asia/Kuala_Lumpur');

    expect($anchor?->format('Y-m-d H:i'))->toBe('2026-10-15 11:02')
        ->and($anchor?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:02');
});

it('accepts single-digit hours and seconds suffixes', function () {
    expect(PrayerClock::parseWallClock('5:40', '2026-10-15', 'Asia/Kuala_Lumpur')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))
        ->toBe('05:40')
        ->and(PrayerClock::parseWallClock('19:02:00', '2026-10-15', 'Asia/Kuala_Lumpur')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))
        ->toBe('19:02');
});

it('returns null for out-of-range and malformed clocks', function () {
    expect(PrayerClock::parseWallClock('24:00', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseWallClock('25:00', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseWallClock('19:60', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseWallClock('bogus', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseWallClock(null, '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull();
});

it('parses absolute datetimes on the prayer day only', function () {
    expect(PrayerClock::parseAbsoluteDatetime('2026-10-15T19:01:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur')?->format('Y-m-d H:i'))
        ->toBe('2026-10-15 11:01')
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-15T19:01:00', '2026-10-15', 'Asia/Kuala_Lumpur')?->format('Y-m-d H:i'))
        ->toBe('2026-10-15 11:01')
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-15T11:01:00Z', '2026-10-15', 'Asia/Kuala_Lumpur')?->format('Y-m-d H:i'))
        ->toBe('2026-10-15 11:01');
});

it('rejects relative and wrong-day datetime text', function () {
    Carbon::setTestNow('2026-01-01');

    expect(PrayerClock::parseAbsoluteDatetime('tomorrow 19:01', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseAbsoluteDatetime('+1 day 19:01', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-14T19:01:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-15T25:01:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseAbsoluteDatetime('19:01', '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull()
        ->and(PrayerClock::parseAbsoluteDatetime(null, '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeNull();

    Carbon::setTestNow();
});

it('accepts UTC crossover but only next-day isha', function () {
    // 20:30-04:00 is past midnight UTC but still the prayer day locally.
    expect(PrayerClock::parseAbsoluteDatetime('2026-10-15T20:30:00-04:00', '2026-10-15', 'America/New_York')?->format('Y-m-d H:i'))
        ->toBe('2026-10-16 00:30')
        // Post-midnight isha belongs to the previous prayer day.
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-16T00:30:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur', true)?->format('Y-m-d H:i'))
        ->toBe('2026-10-15 16:30')
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-16T00:30:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur', false))
        ->toBeNull()
        // Two days out is never legitimate, even for isha.
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-17T00:30:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur', true))
        ->toBeNull();
});

it('constrains overnight isha to the pre-dawn interval', function () {
    // A D+1 evening stamp belongs to the following prayer day, not this row.
    expect(PrayerClock::parseAbsoluteDatetime('2026-10-16T20:11:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur', true))
        ->toBeNull()
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-16T05:59:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur', true)?->format('Y-m-d H:i'))
        ->toBe('2026-10-15 21:59')
        ->and(PrayerClock::parseAbsoluteDatetime('2026-10-16T06:00:00+08:00', '2026-10-15', 'Asia/Kuala_Lumpur', true))
        ->toBeNull();
});

it('rolls clock-only isha past midnight by row chronology', function () {
    $maghrib = PrayerClock::parseWallClock('23:00', '2026-10-15', 'Asia/Kuala_Lumpur');
    $isha = PrayerClock::parseWallClock('00:30', '2026-10-15', 'Asia/Kuala_Lumpur');

    expect(PrayerClock::rollOvernightIsha($isha, $maghrib, 'Asia/Kuala_Lumpur')?->format('Y-m-d H:i'))->toBe('2026-10-15 16:30');

    $normalMaghrib = PrayerClock::parseWallClock('19:01', '2026-10-15', 'Asia/Kuala_Lumpur');
    $normalIsha = PrayerClock::parseWallClock('20:10', '2026-10-15', 'Asia/Kuala_Lumpur');

    expect(PrayerClock::rollOvernightIsha($normalIsha, $normalMaghrib, 'Asia/Kuala_Lumpur')?->format('Y-m-d H:i'))->toBe('2026-10-15 12:10');
});

it('preserves the wall clock across DST transitions when rolling overnight isha', function () {
    // Spring forward: Mar 8 2026, America/New_York (EST -> EDT).
    $maghrib = PrayerClock::parseWallClock('23:00', '2026-03-08', 'America/New_York');
    $isha = PrayerClock::parseWallClock('00:30', '2026-03-08', 'America/New_York');

    $rolled = PrayerClock::rollOvernightIsha($isha, $maghrib, 'America/New_York');

    expect($rolled?->format('Y-m-d H:i'))->toBe('2026-03-09 04:30')
        ->and($rolled?->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-03-09 00:30');

    // Fall back: Nov 1 2026 (EDT -> EST).
    $maghrib = PrayerClock::parseWallClock('23:00', '2026-11-01', 'America/New_York');
    $isha = PrayerClock::parseWallClock('00:30', '2026-11-01', 'America/New_York');

    $rolled = PrayerClock::rollOvernightIsha($isha, $maghrib, 'America/New_York');

    expect($rolled?->format('Y-m-d H:i'))->toBe('2026-11-02 05:30')
        ->and($rolled?->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-02 00:30');
});

it('rejects same-day isha preceding its own maghrib', function () {
    $maghrib = PrayerClock::parseWallClock('19:01', '2026-10-15', 'Asia/Kuala_Lumpur');
    $previousNight = PrayerClock::parseWallClock('00:30', '2026-10-15', 'Asia/Kuala_Lumpur');
    $tonight = PrayerClock::parseWallClock('20:10', '2026-10-15', 'Asia/Kuala_Lumpur');
    $rolled = PrayerClock::rollOvernightIsha($previousNight, $maghrib, 'Asia/Kuala_Lumpur');

    expect(PrayerClock::isIshaAfterMaghrib(['isha' => $previousNight, 'maghrib' => $maghrib]))->toBeFalse()
        ->and(PrayerClock::isIshaAfterMaghrib(['isha' => $tonight, 'maghrib' => $maghrib]))->toBeTrue()
        ->and(PrayerClock::isIshaAfterMaghrib(['isha' => $rolled, 'maghrib' => $maghrib]))->toBeTrue()
        ->and(PrayerClock::isIshaAfterMaghrib(['maghrib' => $maghrib]))->toBeTrue()
        ->and(PrayerClock::isIshaAfterMaghrib([]))->toBeTrue();
});

it('validates final row anchors against the prayer day', function () {
    $maghrib = PrayerClock::parseWallClock('19:00', '2026-10-15', 'Asia/Kuala_Lumpur');
    $tonight = PrayerClock::parseWallClock('20:10', '2026-10-15', 'Asia/Kuala_Lumpur');
    $overnight = PrayerClock::rollOvernightIsha(
        PrayerClock::parseWallClock('00:30', '2026-10-15', 'Asia/Kuala_Lumpur'),
        $maghrib,
        'Asia/Kuala_Lumpur'
    );
    $nextEvening = PrayerClock::rollOvernightIsha(
        PrayerClock::parseWallClock('18:00', '2026-10-15', 'Asia/Kuala_Lumpur'),
        $maghrib,
        'Asia/Kuala_Lumpur'
    );
    $preceding = PrayerClock::parseWallClock('00:30', '2026-10-15', 'Asia/Kuala_Lumpur');

    expect(PrayerClock::isRowOnPrayerDay(['isha' => $tonight, 'maghrib' => $maghrib], '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeTrue()
        ->and(PrayerClock::isRowOnPrayerDay(['isha' => $overnight, 'maghrib' => $maghrib], '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeTrue()
        ->and(PrayerClock::isRowOnPrayerDay(['isha' => $nextEvening, 'maghrib' => $maghrib], '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeFalse()
        ->and(PrayerClock::isRowOnPrayerDay(['isha' => $preceding, 'maghrib' => $maghrib], '2026-10-15', 'Asia/Kuala_Lumpur'))->toBeFalse();
});
