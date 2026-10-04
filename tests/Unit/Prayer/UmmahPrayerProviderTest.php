<?php

use App\Data\Prayer\PrayerQuery;
use App\Services\Prayer\ProviderUnavailable;
use App\Services\Prayer\UmmahPrayerProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

function ummahDayRow(string $date = '2026-10-15'): array
{
    return [
        'date' => $date,
        'prayer_times' => [
            'imsak' => '05:30',
            'fajr' => '05:40',
            'sunrise' => '06:58',
            'dhuhr' => '12:59',
            'asr' => '16:15',
            'maghrib' => '19:01',
            'isha' => '20:10',
        ],
        'prayer_datetimes' => [
            'imsak' => "{$date}T05:30:00+08:00",
            'fajr' => "{$date}T05:40:00+08:00",
            'sunrise' => "{$date}T06:58:00+08:00",
            'dhuhr' => "{$date}T12:59:00+08:00",
            'asr' => "{$date}T16:15:00+08:00",
            'maghrib' => "{$date}T19:01:00+08:00",
            'isha' => "{$date}T20:10:00+08:00",
        ],
    ];
}

function ummahDailyPayload(): array
{
    return [
        'success' => true,
        'service' => 'prayer-times',
        'data' => array_merge(ummahDayRow(), [
            'timezone' => 'Asia/Kuala_Lumpur',
            'location' => ['latitude' => 3.139, 'longitude' => 101.6869],
            // The API normalizes the requested `malaysia` key in its echo.
            'calculation_method' => 'JAKIM',
            'madhab' => 'Shafi',
        ]),
    ];
}

function ummahMonthPayload(): array
{
    return [
        'success' => true,
        'service' => 'prayer-times-month',
        'data' => [
            'timezone' => 'Asia/Kuala_Lumpur',
            'month' => 10,
            'year' => 2026,
            'calculation_method' => 'JAKIM',
            'madhab' => 'Shafi',
            'total_days' => 31,
            'days' => [
                array_merge(ummahDayRow('2026-10-14'), ['day' => 14, 'day_name' => 'Wednesday']),
                array_merge(ummahDayRow('2026-10-15'), ['day' => 15, 'day_name' => 'Thursday']),
            ],
        ],
    ];
}

function ummahQuery(): PrayerQuery
{
    return new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 3.1390, 101.6869, 'WLY01', 'malaysia', 'Shafi');
}

it('parses daily datetimes offset-aware into UTC anchors', function () {
    Http::fake(['ummahapi.com/*' => Http::response(ummahDailyPayload())]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('maghrib')?->format('Y-m-d H:i'))->toBe('2026-10-15 11:01')
        ->and($dto->timeFor('maghrib')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:01')
        ->and($dto->source)->toBe('ummah:malaysia/Shafi')
        ->and($dto->timezoneUsed)->toBe('Asia/Kuala_Lumpur')
        ->and($dto->date)->toBe('2026-10-15');

    Http::assertSent(function ($request) {
        return str_ends_with(strtok($request->url(), '?'), '/api/prayer-times')
            && $request['method'] === 'malaysia'
            && $request['madhab'] === 'Shafi'
            && $request['timezone'] === 'Asia/Kuala_Lumpur'
            && $request['date'] === '2026-10-15';
    });
});

it('falls back to the wall-clock table when datetimes are absent', function () {
    $payload = ummahDailyPayload();
    unset($payload['data']['prayer_datetimes']);
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('maghrib')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:01');
});

it('rejects clock-only isha that rolls into the next evening', function () {
    $payload = ummahDailyPayload();
    unset($payload['data']['prayer_datetimes']);
    $payload['data']['prayer_times']['maghrib'] = '19:00';
    $payload['data']['prayer_times']['isha'] = '18:00';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());
})->throws(ProviderUnavailable::class);

it('skips month days whose clock-only isha rolls into the next evening', function () {
    $payload = ummahMonthPayload();
    unset($payload['data']['days'][0]['prayer_datetimes']);
    $payload['data']['days'][0]['prayer_times']['maghrib'] = '19:00';
    $payload['data']['days'][0]['prayer_times']['isha'] = '18:00';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $days = app(UmmahPrayerProvider::class)->monthlyPrayers(ummahQuery());

    expect(array_keys($days))->toBe(['2026-10-15']);
});

it('interprets offset-less datetimes in the query timezone', function () {
    $payload = ummahDailyPayload();
    $payload['data']['prayer_datetimes'] = array_map(
        fn (string $iso): string => (string) preg_replace('/[+-]\d{2}:?\d{2}$/', '', $iso),
        $payload['data']['prayer_datetimes'],
    );
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    // 19:01 wall clock in Asia/Kuala_Lumpur, not 19:01 UTC.
    expect($dto->timeFor('maghrib')?->format('Y-m-d H:i'))->toBe('2026-10-15 11:01')
        ->and($dto->timeFor('maghrib')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('19:01');
});

it('parses month bulk into date-keyed DTOs', function () {
    Http::fake(['ummahapi.com/*' => Http::response(ummahMonthPayload())]);

    $days = app(UmmahPrayerProvider::class)->monthlyPrayers(ummahQuery());

    expect($days)->toHaveKeys(['2026-10-14', '2026-10-15'])
        ->and($days['2026-10-15']->source)->toBe('ummah:malaysia/Shafi')
        ->and($days['2026-10-15']->timeFor('fajr')?->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('05:40');

    Http::assertSent(function ($request) {
        return str_ends_with(strtok($request->url(), '?'), '/api/prayer-times/month')
            && (int) $request['month'] === 10
            && (int) $request['year'] === 2026;
    });
});

it('rejects payloads whose echo does not match the query', function () {
    $payload = ummahDailyPayload();
    $payload['data']['date'] = '2026-10-16';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());
})->throws(ProviderUnavailable::class);

it('reports unavailable on rate limits and negative success flags', function () {
    Http::fake(['ummahapi.com/*' => Http::response([], 429)]);

    try {
        app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());
        $this->fail('Expected ProviderUnavailable for HTTP 429.');
    } catch (ProviderUnavailable) {
    }

    Http::fake(['ummahapi.com/*' => Http::response(['success' => false])]);

    app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());
})->throws(ProviderUnavailable::class);

it('requires coordinates', function () {
    Http::fake();

    $query = new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', zone: 'WLY01');

    app(UmmahPrayerProvider::class)->dailyPrayers($query);
})->throws(ProviderUnavailable::class);

it('sends the API key as a header and never as a query param', function () {
    config(['services.ummah.key' => 'test-key-123']);
    Http::fake(['ummahapi.com/*' => Http::response(ummahDailyPayload())]);

    app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-API-Key', 'test-key-123')
            && ! isset($request['apikey']);
    });
});

it('resolves the method from the per-country table when the query omits it', function () {
    Http::fake(['ummahapi.com/*' => Http::response(ummahDailyPayload())]);

    $query = new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 3.1390, 101.6869);
    app(UmmahPrayerProvider::class)->dailyPrayers($query);

    Http::assertSent(function ($request) {
        return $request['method'] === 'malaysia' && $request['madhab'] === 'Shafi';
    });
});

it('skips day rows outside the queried month', function () {
    $payload = ummahMonthPayload();
    $payload['data']['days'][] = array_merge(ummahDayRow('2026-11-01'), ['day' => 1, 'day_name' => 'Sunday']);
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $days = app(UmmahPrayerProvider::class)->monthlyPrayers(ummahQuery());

    // Exact keys: the November row must not leak into October coverage.
    expect(array_keys($days))->toBe(['2026-10-14', '2026-10-15'])
        ->and($days)->toHaveCount(2)
        ->and(isset($days['2026-11-01']))->toBeFalse();
});

it('falls back to wall clocks when datetimes name another day', function () {
    $payload = ummahDailyPayload();
    $payload['data']['prayer_datetimes']['maghrib'] = '2026-10-14T19:01:00+08:00';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('maghrib')?->format('Y-m-d H:i'))->toBe('2026-10-15 11:01');
});

it('rejects relative datetime text in favor of wall clocks', function () {
    Carbon::setTestNow('2026-01-01');

    $payload = ummahDailyPayload();
    $payload['data']['prayer_datetimes']['maghrib'] = 'tomorrow 19:01';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('maghrib')?->format('Y-m-d H:i'))->toBe('2026-10-15 11:01');

    Carbon::setTestNow();
});

it('accepts post-midnight isha datetimes', function () {
    $payload = ummahDailyPayload();
    $payload['data']['prayer_datetimes']['isha'] = '2026-10-16T00:30:00+08:00';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('isha')?->format('Y-m-d H:i'))->toBe('2026-10-15 16:30');
});

it('rejects days whose datetimes and wall clocks are both unusable', function () {
    $payload = ummahDailyPayload();
    $payload['data']['prayer_datetimes']['maghrib'] = '2026-10-14T19:01:00+08:00';
    unset($payload['data']['prayer_times']['maghrib']);
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());
})->throws(ProviderUnavailable::class, 'missing [maghrib]');

it('rolls clock-only overnight isha to the next day', function () {
    $payload = ummahDailyPayload();
    unset($payload['data']['prayer_datetimes']);
    $payload['data']['prayer_times']['maghrib'] = '23:00';
    $payload['data']['prayer_times']['isha'] = '00:30';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('isha')?->format('Y-m-d H:i'))->toBe('2026-10-15 16:30')
        ->and($dto->timeFor('isha')?->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'))->toBe('2026-10-16 00:30');
});

it('falls back to wall clocks when absolute isha names the next evening', function () {
    $payload = ummahDailyPayload();
    $payload['data']['prayer_datetimes']['isha'] = '2026-10-16T20:11:00+08:00';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('isha')?->format('Y-m-d H:i'))->toBe('2026-10-15 12:10');
});

it('falls back to wall clocks when same-day absolute isha precedes maghrib', function () {
    $payload = ummahDailyPayload();
    // Same-day stamp, but the previous night's anchor: 00:30 against a
    // 19:01 maghrib. The clock table (20:10) supplies tonight's isha.
    $payload['data']['prayer_datetimes']['isha'] = '2026-10-15T00:30:00+08:00';
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(ummahQuery());

    expect($dto->timeFor('isha')?->format('Y-m-d H:i'))->toBe('2026-10-15 12:10');
});

it('rolls clock-only overnight isha by local calendar across DST transitions', function (string $date, string $utc, string $local) {
    $row = ummahDayRow($date);
    unset($row['prayer_datetimes']);
    $row['prayer_times']['maghrib'] = '23:00';
    $row['prayer_times']['isha'] = '00:30';

    $payload = [
        'success' => true,
        'service' => 'prayer-times',
        'data' => array_merge($row, [
            'timezone' => 'America/New_York',
            'location' => ['latitude' => 40.7128, 'longitude' => -74.0060],
            'calculation_method' => 'JAKIM',
            'madhab' => 'Shafi',
        ]),
    ];
    Http::fake(['ummahapi.com/*' => Http::response($payload)]);

    $dto = app(UmmahPrayerProvider::class)->dailyPrayers(
        new PrayerQuery('US', $date, 'America/New_York', 40.7128, -74.0060)
    );

    expect($dto->timeFor('isha')?->format('Y-m-d H:i'))->toBe($utc)
        ->and($dto->timeFor('isha')?->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe($local);
})->with([
    'spring forward' => ['2026-03-08', '2026-03-09 04:30', '2026-03-09 00:30'],
    'fall back' => ['2026-11-01', '2026-11-02 05:30', '2026-11-02 00:30'],
]);
