<?php

use App\Services\Prayer\RamadanGate;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('treats announced windows as exact with no tolerance', function () {
    $gate = app(RamadanGate::class);

    // Announced 2026: 02-18 .. 03-19.
    $tz = 'Asia/Kuala_Lumpur';

    expect($gate->isRamadan(Carbon::parse('2026-02-18', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-03-19', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-02-25', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-02-17', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2026-03-20', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2026-04-01', $tz), $tz, 'MY'))->toBeFalse();
});

it('governs Ramadan 1448 from the announced MY window', function () {
    $gate = app(RamadanGate::class);
    $tz = 'Asia/Kuala_Lumpur';

    // JAKIM e-Solat tarikhtakwim: 1 Ramadan 1448 = 2027-02-08,
    // 1 Syawal 1448 = 2027-03-10 (30-day month). Tarawih evenings
    // 02-07 .. 03-08, exact — the tabular ±1 tolerance must not leak
    // Feb 6 in.
    expect($gate->isRamadan(Carbon::parse('2027-02-07', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2027-03-08', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2027-02-06', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2027-03-09', $tz), $tz, 'MY'))->toBeFalse();
});

it('evaluates the day in the given timezone', function () {
    $gate = app(RamadanGate::class);

    // 2026-02-17 23:30 UTC is already 2026-02-18 in Kuala Lumpur.
    expect($gate->isRamadan(Carbon::parse('2026-02-17 23:30:00', 'UTC'), 'Asia/Kuala_Lumpur', 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-02-17 23:30:00', 'UTC'), 'UTC', 'MY'))->toBeFalse();
});

it('converts Gregorian dates to tabular Hijri parts', function () {
    $gate = app(RamadanGate::class);

    // Vectors confirmed against Ummah moment-hijri and Aladhan HJCoSA.
    expect($gate->hijriParts(2026, 2, 18))->toBe(['year' => 1447, 'month' => 9, 'day' => 1])
        ->and($gate->hijriParts(2025, 3, 1))->toBe(['year' => 1446, 'month' => 9, 'day' => 1])
        ->and($gate->hijriParts(2025, 3, 29))->toBe(['year' => 1446, 'month' => 9, 'day' => 29]);
});

it('estimates unannounced years with ±1-day tolerance', function () {
    $gate = app(RamadanGate::class);
    $tz = 'Asia/Kuala_Lumpur';

    // Tabular fasting 1446 runs 2025-03-01 .. 2025-03-30 (30 days);
    // the gate answers Tarawih evenings with ±1-day fasting tolerance,
    // so it admits 02-27 (F-2) .. 03-30 (E).
    expect($gate->isRamadan(Carbon::parse('2025-03-15', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2025-02-27', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2025-02-26', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2025-03-30', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2025-03-31', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2024-06-15', $tz), $tz, 'MY'))->toBeFalse();
});

it('covers December straddlers and years beyond the announced table', function () {
    $gate = app(RamadanGate::class);
    $tz = 'Asia/Kuala_Lumpur';

    // Only declared seasons sit in `announced`; Ramadan 1452 (December
    // 2030) and everything past it fall to the estimate instead of
    // hard-failing false like the old year-keyed tables. Tabular fasting
    // runs 12-26 .. 01-24, so tolerated evenings run 12-24 .. 01-24.
    expect($gate->isRamadan(Carbon::parse('2030-12-25', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2030-12-24', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2030-12-23', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2030-12-01', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2031-01-10', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2031-01-24', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2031-01-25', $tz), $tz, 'MY'))->toBeFalse();
});

it('estimates undeclared future seasons with tolerance instead of forecast-exact windows', function () {
    $gate = app(RamadanGate::class);
    $tz = 'Asia/Kuala_Lumpur';

    // 2027 is declared (see above); 2028 has no row, so the tabular
    // estimate governs with tolerance. Tabular fasting 1449 runs
    // 2028-01-28 .. 2028-02-26 (30 days); tolerated evenings run
    // 01-26 (F-2) .. 02-26 (E).
    expect($gate->isRamadan(Carbon::parse('2028-01-28', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2028-02-26', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2028-02-27', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2028-02-28', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2028-01-26', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2028-01-25', $tz), $tz, 'MY'))->toBeFalse();
});

it('governs each country by its own declared boundaries', function () {
    config(['prayer.ramadan.announced' => [
        'MY' => [2026 => ['start' => '02-18', 'end' => '03-19']],
        'XX' => [2026 => ['start' => '02-19', 'end' => '03-20']],
    ]]);

    $gate = app(RamadanGate::class);
    $tz = 'Asia/Kuala_Lumpur';

    // Each declaration is exact for its own country only.
    expect($gate->isRamadan(Carbon::parse('2026-02-18', $tz), $tz, 'MY'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-02-18', $tz), $tz, 'XX'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2026-03-20', $tz), $tz, 'XX'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-03-20', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2026-02-25', $tz), $tz, 'XX'))->toBeTrue();
});

it('estimates for unmapped countries and missing country identity', function () {
    $gate = app(RamadanGate::class);
    $tz = 'Asia/Kuala_Lumpur';

    // Tabular fasting 1447 runs 2026-02-18 .. 2026-03-19; tolerated
    // evenings run 02-16 (F-2) .. 03-19 (E), so 02-16 reads true here
    // while MY's exact declaration governs it false. No declaration
    // is ever borrowed.
    expect($gate->isRamadan(Carbon::parse('2026-02-16', $tz), $tz, 'ZZ'))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-02-16', $tz), $tz))->toBeTrue()
        ->and($gate->isRamadan(Carbon::parse('2026-02-16', $tz), $tz, 'MY'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2026-02-15', $tz), $tz, 'ZZ'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2026-04-01', $tz), $tz, 'ZZ'))->toBeFalse()
        ->and($gate->isRamadan(Carbon::parse('2026-02-25', $tz), $tz, 'ZZ'))->toBeTrue();
});
