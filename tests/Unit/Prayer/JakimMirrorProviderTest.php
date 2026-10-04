<?php

use App\Data\Prayer\PrayerQuery;
use App\Services\Prayer\JakimMirrorProvider;
use App\Services\Prayer\ProviderUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

function jakimV2MonthPayload(): array
{
    return [
        'zone' => 'WLY01',
        'year' => 2026,
        'month' => 'OCT',
        'month_number' => 10,
        'last_updated' => null,
        'prayers' => [
            ['day' => 14, 'hijri' => '1448-05-03', 'imsak' => 1791927600, 'fajr' => 1791928200, 'syuruk' => 1791932220, 'dhuha' => 1791933720, 'dhuhr' => 1791954120, 'asr' => 1791965880, 'maghrib' => 1791975720, 'isha' => 1791979860],
            ['day' => 15, 'hijri' => '1448-05-04', 'imsak' => 1792014000, 'fajr' => 1792014600, 'syuruk' => 1792018620, 'dhuha' => 1792020120, 'dhuhr' => 1792040520, 'asr' => 1792052280, 'maghrib' => 1792062120, 'isha' => 1792066260],
        ],
    ];
}

function jakimV1DayPayload(): array
{
    return [
        'prayerTime' => ['hijri' => '1448-05-04', 'date' => '15-Oct-2026', 'day' => 'Thursday', 'imsak' => '05:40:00', 'fajr' => '05:50:00', 'syuruk' => '06:57:00', 'dhuha' => '07:22:00', 'dhuhr' => '13:02:00', 'asr' => '16:18:00', 'maghrib' => '19:02:00', 'isha' => '20:11:00'],
        'status' => 'OK!',
        'serverTime' => '2026-10-03 07:53:49',
        'periodType' => 'day',
        'lang' => '',
        'zone' => 'WLY01',
        'bearing' => '',
    ];
}

function jakimQuery(): PrayerQuery
{
    return new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 3.1390, 101.6869, 'WLY01');
}

it('parses V2 monthly epochs into UTC anchors', function () {
    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response(jakimV2MonthPayload())]);

    $days = app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());

    expect($days)->toHaveKeys(['2026-10-14', '2026-10-15']);

    $dto = $days['2026-10-15'];

    // Sanity vector: 1792014000 == 2026-10-15 05:40 MYT imsak.
    expect($dto->timeFor('imsak')?->format('Y-m-d H:i'))->toBe('2026-10-14 21:40')
        ->and($dto->timeFor('imsak')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('05:40')
        ->and($dto->timeFor('sunrise')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('06:57')
        ->and($dto->source)->toBe('jakim:v2/WLY01')
        ->and($dto->date)->toBe('2026-10-15')
        ->and($dto->zoneOrCell)->toBe('WLY01');
});

it('picks the queried day for daily prayers', function () {
    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response(jakimV2MonthPayload())]);

    $dto = app(JakimMirrorProvider::class)->dailyPrayers(jakimQuery());

    expect($dto->date)->toBe('2026-10-15')
        ->and($dto->timeFor('maghrib')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:02');
});

it('falls back to the V1 day endpoint when the month is unpublished', function () {
    Http::fake([
        'api.waktusolat.app/v2/solat/*' => Http::response(['message' => 'No data found'], 404),
        'api.waktusolat.app/solat/*' => Http::response(jakimV1DayPayload()),
    ]);

    $dto = app(JakimMirrorProvider::class)->dailyPrayers(jakimQuery());

    expect($dto->source)->toBe('jakim:v1/WLY01')
        ->and($dto->timeFor('fajr')?->format('Y-m-d H:i'))->toBe('2026-10-14 21:50')
        ->and($dto->timeFor('fajr')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('05:50');
});

it('reports unavailable when neither V2 nor V1 serves the day', function () {
    Http::fake([
        'api.waktusolat.app/v2/solat/*' => Http::response(['message' => 'No data found'], 404),
        'api.waktusolat.app/solat/*' => Http::response(['message' => 'No data found'], 404),
    ]);

    app(JakimMirrorProvider::class)->dailyPrayers(jakimQuery());
})->throws(ProviderUnavailable::class);

it('rejects a V1 day response echoed for another date', function (string $echo) {
    $payload = jakimV1DayPayload();
    $payload['prayerTime']['date'] = $echo;

    Http::fake([
        'api.waktusolat.app/v2/solat/*' => Http::response(['message' => 'No data found'], 404),
        'api.waktusolat.app/solat/*' => Http::response($payload),
    ]);

    app(JakimMirrorProvider::class)->dailyPrayers(jakimQuery());
})->with([
    'another day' => ['16-Oct-2026'],
    'another month' => ['15-Nov-2026'],
    'unreadable echo' => ['not-a-date'],
    'missing echo' => [''],
])->throws(ProviderUnavailable::class, 'date mismatch');

it('rejects payloads whose echo does not match the query', function () {
    $payload = jakimV2MonthPayload();
    $payload['zone'] = 'SGR01';

    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());
})->throws(ProviderUnavailable::class);

it('serves Malaysia only and requires a zone', function () {
    $provider = app(JakimMirrorProvider::class);

    expect($provider->supports('MY'))->toBeTrue()
        ->and($provider->supports('ID'))->toBeFalse();

    $provider->dailyPrayers(new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur'));
})->throws(ProviderUnavailable::class);

it('skips prayer rows with invalid day numbers', function () {
    $payload = jakimV2MonthPayload();
    $payload['prayers'][] = array_merge($payload['prayers'][0], ['day' => 32]);
    $payload['prayers'][] = array_merge($payload['prayers'][0], ['day' => 0]);
    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    $days = app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());

    // Exact keys: the day-32 and day-0 rows must not mint outside-month
    // keys that inflate coverage counts.
    expect(array_keys($days))->toBe(['2026-10-14', '2026-10-15'])
        ->and($days)->toHaveCount(2)
        ->and(isset($days['2026-10-32'], $days['2026-10-00']))->toBeFalse();
});

it('skips V2 rows whose absolute stamps fall outside the prayer day', function () {
    $payload = jakimV2MonthPayload();
    // Correct zone/month echo, but day 15 carries day 14's maghrib epoch.
    $payload['prayers'][1]['maghrib'] = $payload['prayers'][0]['maghrib'];

    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    $days = app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());

    expect(array_keys($days))->toBe(['2026-10-14']);
});

it('rejects a V2 month when every row carries wrong-day stamps', function () {
    $payload = jakimV2MonthPayload();
    $payload['prayers'] = [$payload['prayers'][1]];
    $payload['prayers'][0]['fajr'] -= 86400;

    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());
})->throws(ProviderUnavailable::class, 'no usable days');

it('accepts post-midnight isha stamps on V2 rows', function () {
    $payload = jakimV2MonthPayload();
    $payload['prayers'][1]['isha'] = CarbonImmutable::parse('2026-10-16 00:30:00', 'Asia/Kuala_Lumpur')->timestamp;

    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    $days = app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());

    expect($days['2026-10-15']->timeFor('isha')?->format('Y-m-d H:i'))->toBe('2026-10-15 16:30');
});

it('rejects V2 rows whose isha names the next evening', function () {
    $payload = jakimV2MonthPayload();
    $payload['prayers'][1]['isha'] = CarbonImmutable::parse('2026-10-16 20:11:00', 'Asia/Kuala_Lumpur')->timestamp;

    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    $days = app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());

    expect(array_keys($days))->toBe(['2026-10-14']);
});

it('rejects V2 rows whose same-day isha precedes maghrib', function () {
    $payload = jakimV2MonthPayload();
    // Correct zone/month echo, but day 15 carries the previous night's
    // isha stamped with today's date: 00:30 against a 19:02 maghrib.
    $payload['prayers'][1]['isha'] = CarbonImmutable::parse('2026-10-15 00:30:00', 'Asia/Kuala_Lumpur')->timestamp;

    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    $days = app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());

    expect(array_keys($days))->toBe(['2026-10-14']);
});

it('rejects V1 rows whose same-day isha precedes maghrib', function () {
    $payload = jakimV1DayPayload();
    $payload['prayerTime']['isha'] = '00:30:00';
    Http::fake([
        'api.waktusolat.app/v2/solat/*' => Http::response(['message' => 'No data found'], 404),
        'api.waktusolat.app/solat/*' => Http::response($payload),
    ]);

    app(JakimMirrorProvider::class)->dailyPrayers(jakimQuery());
})->throws(ProviderUnavailable::class);

it('reports unavailable for array-valued V2 zone echoes', function () {
    $payload = jakimV2MonthPayload();
    $payload['zone'] = [];
    Http::fake(['api.waktusolat.app/v2/solat/*' => Http::response($payload)]);

    app(JakimMirrorProvider::class)->monthlyPrayers(jakimQuery());
})->throws(ProviderUnavailable::class);

it('reports unavailable for array-valued V1 zone echoes', function () {
    $payload = jakimV1DayPayload();
    $payload['zone'] = ['WLY01'];
    Http::fake([
        'api.waktusolat.app/v2/solat/*' => Http::response(['message' => 'No data found'], 404),
        'api.waktusolat.app/solat/*' => Http::response($payload),
    ]);

    app(JakimMirrorProvider::class)->dailyPrayers(jakimQuery());
})->throws(ProviderUnavailable::class);

it('reports unavailable for object-valued V1 date echoes', function () {
    $payload = jakimV1DayPayload();
    $payload['prayerTime']['date'] = ['date' => '15-Oct-2026'];
    Http::fake([
        'api.waktusolat.app/v2/solat/*' => Http::response(['message' => 'No data found'], 404),
        'api.waktusolat.app/solat/*' => Http::response($payload),
    ]);

    app(JakimMirrorProvider::class)->dailyPrayers(jakimQuery());
})->throws(ProviderUnavailable::class);
