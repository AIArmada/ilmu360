<?php

use App\Data\Prayer\PrayerQuery;
use App\Services\Prayer\AladhanPrayerProvider;
use App\Services\Prayer\ProviderUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

function aladhanTimingsPayload(): array
{
    return [
        'code' => 200,
        'status' => 'OK',
        'data' => [
            'timings' => [
                'Fajr' => '05:40',
                'Sunrise' => '06:58',
                'Dhuhr' => '12:59',
                'Asr' => '16:17',
                'Sunset' => '19:00',
                'Maghrib' => '19:00',
                'Isha' => '20:10',
                'Imsak' => '05:30',
                'Midnight' => '00:59',
                'Firstthird' => '23:00',
                'Lastthird' => '02:59',
            ],
            'date' => [
                'gregorian' => ['date' => '15-10-2026', 'day' => '15', 'month' => ['number' => 10], 'year' => '2026'],
            ],
            'meta' => [
                'latitude' => 3.139,
                'longitude' => 101.6869,
                'timezone' => 'Asia/Kuala_Lumpur',
                'method' => ['id' => 17, 'name' => 'Jabatan Kemajuan Islam Malaysia (JAKIM)'],
                'school' => 'STANDARD',
            ],
        ],
    ];
}

function aladhanQuery(?string $madhab = 'Shafi'): PrayerQuery
{
    return new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 3.1390, 101.6869, 'WLY01', null, $madhab);
}

it('parses Aladhan timings in the query timezone into UTC anchors', function () {
    Http::fake(['api.aladhan.com/*' => Http::response(aladhanTimingsPayload())]);

    $dto = app(AladhanPrayerProvider::class)->dailyPrayers(aladhanQuery());

    expect($dto->timeFor('maghrib')?->format('Y-m-d H:i'))->toBe('2026-10-15 11:00')
        ->and($dto->timeFor('maghrib')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:00')
        ->and($dto->source)->toBe('aladhan:17/Shafi')
        ->and($dto->date)->toBe('2026-10-15');

    Http::assertSent(function ($request) {
        return str_ends_with(strtok($request->url(), '?'), '/timings/15-10-2026')
            && (int) $request['method'] === 17
            && (int) $request['school'] === 0;
    });
});

it('uses the Hanafi school when the madhab requires it', function () {
    Http::fake(['api.aladhan.com/*' => Http::response(aladhanTimingsPayload())]);

    app(AladhanPrayerProvider::class)->dailyPrayers(aladhanQuery('Hanafi'));

    Http::assertSent(function ($request) {
        return (int) $request['school'] === 1;
    });
});

it('strips timezone suffixes from timing strings', function () {
    $payload = aladhanTimingsPayload();
    $payload['data']['timings']['Maghrib'] = '19:00 (MYT)';
    Http::fake(['api.aladhan.com/*' => Http::response($payload)]);

    $dto = app(AladhanPrayerProvider::class)->dailyPrayers(aladhanQuery());

    expect($dto->timeFor('maghrib')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:00');
});

it('rejects payloads whose date echo does not match the query', function () {
    $payload = aladhanTimingsPayload();
    $payload['data']['date']['gregorian']['date'] = '16-10-2026';
    Http::fake(['api.aladhan.com/*' => Http::response($payload)]);

    app(AladhanPrayerProvider::class)->dailyPrayers(aladhanQuery());
})->throws(ProviderUnavailable::class);

it('reports unavailable on errors and missing coordinates', function () {
    Http::fake(['api.aladhan.com/*' => Http::response([], 500)]);

    try {
        app(AladhanPrayerProvider::class)->dailyPrayers(aladhanQuery());
        $this->fail('Expected ProviderUnavailable for HTTP 500.');
    } catch (ProviderUnavailable) {
    }

    $query = new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', zone: 'WLY01');

    app(AladhanPrayerProvider::class)->dailyPrayers($query);
})->throws(ProviderUnavailable::class);

it('preserves the actual instant when the response timezone differs from the query', function () {
    Http::fake(['api.aladhan.com/*' => Http::response(aladhanTimingsPayload())]);

    // The query claims UTC, but the payload declares Asia/Kuala_Lumpur and
    // carries 19:00 local clocks: the instant must be 11:00 UTC, not 19:00.
    $query = new PrayerQuery('MY', '2026-10-15', 'UTC', 3.1390, 101.6869, 'WLY01');

    $dto = app(AladhanPrayerProvider::class)->dailyPrayers($query);

    expect($dto->timeFor('maghrib')?->format('Y-m-d H:i'))->toBe('2026-10-15 11:00')
        ->and($dto->timezoneUsed)->toBe('Asia/Kuala_Lumpur');
});

it('rolls overnight isha to the next day by row chronology', function () {
    $payload = aladhanTimingsPayload();
    $payload['data']['timings']['Maghrib'] = '23:00';
    $payload['data']['timings']['Isha'] = '00:30';
    Http::fake(['api.aladhan.com/*' => Http::response($payload)]);

    $dto = app(AladhanPrayerProvider::class)->dailyPrayers(aladhanQuery());

    expect($dto->timeFor('isha')?->format('Y-m-d H:i'))->toBe('2026-10-15 16:30')
        ->and($dto->timeFor('isha')?->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe('2026-10-16 00:30');
});

it('rejects timings whose clock-only isha rolls into the next evening', function () {
    $payload = aladhanTimingsPayload();
    $payload['data']['timings']['Maghrib'] = '19:00';
    $payload['data']['timings']['Isha'] = '18:00';
    Http::fake(['api.aladhan.com/*' => Http::response($payload)]);

    app(AladhanPrayerProvider::class)->dailyPrayers(aladhanQuery());
})->throws(ProviderUnavailable::class);

it('rolls overnight isha by local calendar across DST transitions', function (string $date, string $echo, string $utc, string $local) {
    $payload = aladhanTimingsPayload();
    $payload['data']['date']['gregorian']['date'] = $echo;
    $payload['data']['meta']['timezone'] = 'America/New_York';
    $payload['data']['timings']['Maghrib'] = '23:00';
    $payload['data']['timings']['Isha'] = '00:30';
    Http::fake(['api.aladhan.com/*' => Http::response($payload)]);

    $dto = app(AladhanPrayerProvider::class)->dailyPrayers(
        new PrayerQuery('US', $date, 'America/New_York', 40.7128, -74.0060, null, null, 'Shafi')
    );

    expect($dto->timeFor('isha')?->format('Y-m-d H:i'))->toBe($utc)
        ->and($dto->timeFor('isha')?->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe($local);
})->with([
    'spring forward' => ['2026-03-08', '08-03-2026', '2026-03-09 04:30', '2026-03-09 00:30'],
    'fall back' => ['2026-11-01', '01-11-2026', '2026-11-02 05:30', '2026-11-02 00:30'],
]);
