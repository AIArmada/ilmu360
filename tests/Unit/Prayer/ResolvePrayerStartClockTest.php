<?php

use App\Actions\Prayer\BuildPrayerPreviewAction;
use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\EventPrayerTime;
use App\Services\Prayer\PrayerTimesCache;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Submission\SubmissionTimingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class);

function jakartaFajrDto(string $fajrUtc = '2026-10-14 21:40:00', string $ishaUtc = '2026-10-15 12:05:00'): PrayerTimesDTO
{
    return new PrayerTimesDTO(
        timesUtc: [
            'fajr' => CarbonImmutable::parse($fajrUtc, 'UTC'),
            'sunrise' => CarbonImmutable::parse('2026-10-14 22:55:00', 'UTC'),
            'dhuhr' => CarbonImmutable::parse('2026-10-15 04:45:00', 'UTC'),
            'asr' => CarbonImmutable::parse('2026-10-15 08:00:00', 'UTC'),
            'maghrib' => CarbonImmutable::parse('2026-10-15 10:55:00', 'UTC'),
            'isha' => CarbonImmutable::parse($ishaUtc, 'UTC'),
        ],
        source: 'aladhan:3/Shafi',
        fetchedAt: CarbonImmutable::now('UTC'),
        timezoneUsed: 'Asia/Jakarta',
        date: '2026-10-15',
        zoneOrCell: 'ID:Asia/Jakarta:aladhan:3:Shafi:-6.20:106.80',
    );
}

function warmJakartaCell(PrayerTimesDTO $dto): void
{
    Bus::fake();
    config()->set('prayer.enabled', true);

    app(PrayerTimesCache::class)->putDaily(
        'aladhan',
        'ID:Asia/Jakarta:aladhan:3:Shafi:-6.20:106.80',
        '2026-10-15',
        $dto
    );
}

it('preserves the UTC instant across the Jakarta calendar boundary end to end', function () {
    warmJakartaCell(jakartaFajrDto());

    $resolved = app(ResolvePrayerStartClockAction::class)->handle(
        'ID', '2026-10-15', 'Asia/Jakarta', EventPrayerTime::SelepasSubuh, -6.2, 106.8,
    );

    // Oct 15 04:40 WIB lives on Oct 14 in UTC; the local day stays Oct 15.
    expect($resolved['clock'])->toBe('04:45')
        ->and($resolved['date'])->toBe('2026-10-15');

    $persisted = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-10-15',
        'prayer_time' => EventPrayerTime::SelepasSubuh->value,
        'timezone' => 'Asia/Jakarta',
        'resolved_start_clock' => $resolved['clock'],
        'resolved_start_date' => $resolved['date'],
        'prayer_source' => $resolved['source'],
        'prayer_fetched_at' => $resolved['fetched_at'],
        'prayer_zone' => $resolved['zone'],
    ]);

    expect($persisted['starts_at']->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-10-14 21:45:00');
});

it('resolves sebelum subuh fifteen minutes before the fajr anchor', function () {
    warmJakartaCell(jakartaFajrDto());

    $resolved = app(ResolvePrayerStartClockAction::class)->handle(
        'ID', '2026-10-15', 'Asia/Jakarta', EventPrayerTime::SebelumSubuh, -6.2, 106.8,
    );

    // Fajr anchor 04:40 WIB - 15 => 04:25, same local day.
    expect($resolved['clock'])->toBe('04:25')
        ->and($resolved['date'])->toBe('2026-10-15');
});

it('resolves rolled overnight isha to the next local day', function () {
    // Overnight row: isha 00:30 WIB renders on Oct 16. The pre-dawn spill
    // passes cache validation, and resolution must carry the instant's own
    // day instead of silently attaching the clock to Oct 15 (24h early).
    warmJakartaCell(jakartaFajrDto('2026-10-14 21:40:00', '2026-10-15 17:30:00'));

    $resolved = app(ResolvePrayerStartClockAction::class)->handle(
        'ID', '2026-10-15', 'Asia/Jakarta', EventPrayerTime::SelepasIsyak, -6.2, 106.8,
    );

    expect($resolved['clock'])->toBe('00:35')
        ->and($resolved['date'])->toBe('2026-10-16');
});

it('previews the Jakarta boundary clock from the anchor instant', function () {
    warmJakartaCell(jakartaFajrDto());

    $preview = app(BuildPrayerPreviewAction::class)->handle(
        'ID', '2026-10-15', 'Asia/Jakarta', null, -6.2, 106.8, null, false,
    );

    expect($preview['starts'][EventPrayerTime::SelepasSubuh->value])->toBe('04:45')
        ->and($preview['source'])->toBe('aladhan:3/Shafi');
});

it('persists the second fold occurrence through admin and frontend paths', function () {
    Bus::fake();
    config()->set('prayer.enabled', true);

    // Maghrib at the second 01:15 EST (06:15Z) on the fall-back day.
    $dto = new PrayerTimesDTO(
        timesUtc: [
            'fajr' => CarbonImmutable::parse('2026-11-01 09:30:00', 'UTC'),
            'sunrise' => CarbonImmutable::parse('2026-11-01 10:45:00', 'UTC'),
            'dhuhr' => CarbonImmutable::parse('2026-11-01 15:40:00', 'UTC'),
            'asr' => CarbonImmutable::parse('2026-11-01 19:30:00', 'UTC'),
            'maghrib' => CarbonImmutable::parse('2026-11-01 06:15:00', 'UTC'),
            'isha' => CarbonImmutable::parse('2026-11-01 07:30:00', 'UTC'),
        ],
        source: 'aladhan:3/Shafi',
        fetchedAt: CarbonImmutable::now('UTC'),
        timezoneUsed: 'America/New_York',
        date: '2026-11-01',
        zoneOrCell: 'US:America/New_York:aladhan:3:Shafi:40.71:-74.01',
    );
    app(PrayerTimesCache::class)->putDaily(
        'aladhan', 'US:America/New_York:aladhan:3:Shafi:40.71:-74.01', '2026-11-01', $dto
    );

    $resolved = app(ResolvePrayerStartClockAction::class)->handle(
        'US', '2026-11-01', 'America/New_York', EventPrayerTime::SelepasMaghrib, 40.7128, -74.0060,
    );

    // 06:15Z + 5 renders the second 01:20 EST.
    expect($resolved['clock'])->toBe('01:20')
        ->and($resolved['date'])->toBe('2026-11-01');

    // Wall reconstruction would read back the first occurrence (05:20Z);
    // the carried instant persists the true second occurrence (06:20Z).
    $admin = AdminEventTimeMapper::normalizeForPersistence([
        'event_date' => '2026-11-01',
        'prayer_time' => EventPrayerTime::SelepasMaghrib->value,
        'timezone' => 'America/New_York',
        'resolved_start_clock' => $resolved['clock'],
        'resolved_start_date' => $resolved['date'],
        'resolved_start_instant' => $resolved['starts_at'],
    ]);

    expect($admin['starts_at']->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-11-01 06:20:00');

    $submit = app(SubmissionTimingPolicy::class)->resolveStartsAt(
        '2026-11-01', EventPrayerTime::SelepasMaghrib->value, null, 'America/New_York', '',
        $resolved['clock'], $resolved['date'], $resolved['starts_at'],
    );

    expect($submit->setTimezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-11-01 06:20:00');
});
