<?php

use App\Data\Prayer\PrayerTimesDTO;
use Carbon\CarbonImmutable;

function prayerDtoTimes(): array
{
    $day = CarbonImmutable::parse('2026-10-15 00:00:00', 'UTC');

    return [
        'fajr' => $day->setTime(21, 50),
        'sunrise' => $day->setTime(22, 57),
        'dhuhr' => $day->setTime(5, 2),
        'asr' => $day->setTime(8, 18),
        'maghrib' => $day->setTime(11, 2),
        'isha' => $day->setTime(12, 11),
    ];
}

it('returns times by key and null for unknown keys', function () {
    $dto = new PrayerTimesDTO(
        timesUtc: prayerDtoTimes(),
        source: 'jakim:v2/WLY01',
        fetchedAt: CarbonImmutable::now('UTC'),
        timezoneUsed: 'Asia/Kuala_Lumpur',
        date: '2026-10-15',
        zoneOrCell: 'WLY01',
    );

    expect($dto->timeFor('maghrib')?->format('H:i'))->toBe('11:02')
        ->and($dto->timeFor('sunrise')?->format('H:i'))->toBe('22:57')
        ->and($dto->timeFor('dhuha'))->toBeNull();
});

it('rejects payloads missing a required anchor', function () {
    $times = prayerDtoTimes();
    unset($times['asr']);

    new PrayerTimesDTO(
        timesUtc: $times,
        source: 'jakim:v2/WLY01',
        fetchedAt: CarbonImmutable::now('UTC'),
        timezoneUsed: 'Asia/Kuala_Lumpur',
        date: '2026-10-15',
    );
})->throws(InvalidArgumentException::class);
