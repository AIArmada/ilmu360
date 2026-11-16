<?php

declare(strict_types=1);

use App\Shared\Prayer\EventPrayerTime;
use App\Shared\Prayer\HardcodedPrayerFallback;

it('covers every non-custom prayer timing with a valid clock', function () {
    $expected = collect(EventPrayerTime::cases())
        ->reject(fn (EventPrayerTime $case) => $case->isCustomTime())
        ->map(fn (EventPrayerTime $case) => $case->value)
        ->sort()
        ->values()
        ->all();

    expect(array_keys(HardcodedPrayerFallback::MAP))->toEqualCanonicalizing($expected)
        ->and(HardcodedPrayerFallback::MAP)->each->toMatch('/^([01]?\d|2[0-3]):[0-5]\d$/');
});

it('resolves clocks for enum cases and raw values', function () {
    expect(HardcodedPrayerFallback::clockFor(EventPrayerTime::SelepasMaghrib))->toBe('20:00')
        ->and(HardcodedPrayerFallback::clockFor('selepas_subuh'))->toBe('06:30')
        ->and(HardcodedPrayerFallback::clockFor(EventPrayerTime::LainWaktu))->toBeNull()
        ->and(HardcodedPrayerFallback::clockFor('unknown'))->toBeNull();
});
