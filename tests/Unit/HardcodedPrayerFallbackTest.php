<?php

use App\Enums\EventPrayerTime;
use App\Services\Prayer\HardcodedPrayerFallback;

it('covers every non-custom prayer timing with a valid clock', function () {
    $expected = collect(EventPrayerTime::cases())
        ->reject(fn (EventPrayerTime $case) => $case->isCustomTime())
        ->map(fn (EventPrayerTime $case) => $case->value)
        ->sort()
        ->values()
        ->all();

    expect(array_keys(HardcodedPrayerFallback::MAP))->toEqualCanonicalizing($expected);

    foreach (HardcodedPrayerFallback::MAP as $clock) {
        expect($clock)->toMatch('/^([01]?\d|2[0-3]):[0-5]\d$/');
    }
});

it('resolves clocks for enum cases and raw values', function () {
    expect(HardcodedPrayerFallback::clockFor(EventPrayerTime::SelepasMaghrib))->toBe('20:00');
    expect(HardcodedPrayerFallback::clockFor('selepas_subuh'))->toBe('06:30');
    expect(HardcodedPrayerFallback::clockFor(EventPrayerTime::LainWaktu))->toBeNull();
    expect(HardcodedPrayerFallback::clockFor('unknown'))->toBeNull();
});
