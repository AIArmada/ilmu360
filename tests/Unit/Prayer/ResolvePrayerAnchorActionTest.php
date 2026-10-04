<?php

use App\Actions\Prayer\ResolvePrayerAnchorAction;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\PrayerReference;
use App\Jobs\RefreshPrayerTimes;
use App\Services\Prayer\AladhanPrayerProvider;
use App\Services\Prayer\PrayerTimesCache;
use App\Services\Prayer\UmmahPrayerProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

function anchorQuery(): PrayerQuery
{
    return new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 3.1390, 101.6869, 'WLY01');
}

it('resolves anchors from warm cache without HTTP', function () {
    Http::fake();
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib);

    expect($anchor['clock'])->toBe('19:02')
        ->and($anchor['source'])->toBe('jakim:v2/WLY01')
        ->and($anchor['stale'])->toBeFalse();

    Http::assertNothingSent();
});

it('maps Friday prayers to the Dhuhr anchor', function () {
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::FridayPrayer);

    // Dhuhr fixture anchor is 13:02 MYT.
    expect($anchor['clock'])->toBe('13:02');
});

it('dispatches a refresh and degrades gracefully on a cold cache', function () {
    Bus::fake();

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib);

    expect($anchor)->toBeNull();

    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);
});

it('serves stale cache on a cold cache when available', function () {
    Bus::fake();
    $cache = app(PrayerTimesCache::class);
    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');
    $cache->forget($cache->monthlyKey('WLY01', '2026-10', 'MY'));

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib);

    expect($anchor['clock'])->toBe('19:02')
        ->and($anchor['stale'])->toBeTrue();
});

it('fetches live only when explicitly allowed', function () {
    Bus::fake();
    Http::fake(['api.waktusolat.app/*' => Http::response(refreshV2Payload())]);

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib, true);

    expect($anchor['clock'])->toBe('19:02')
        ->and($anchor['source'])->toBe('jakim:v2/WLY01');

    Bus::assertNothingDispatched();
});

it('queues a refresh when the live attempt fails', function () {
    Bus::fake();
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response([], 500),
        'api.aladhan.com/*' => Http::response([], 500),
    ]);

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib, true);

    expect($anchor)->toBeNull();

    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);
});

function globalQuery(): PrayerQuery
{
    return new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8);
}

function globalUmmahDayPayload(): array
{
    return [
        'success' => true,
        'service' => 'prayer-times',
        'data' => [
            'date' => '2026-10-15',
            'timezone' => 'Asia/Jakarta',
            'calculation_method' => 'MuslimWorldLeague',
            'madhab' => 'Shafi',
            'prayer_times' => [
                'fajr' => '04:40', 'sunrise' => '05:55', 'dhuhr' => '11:59',
                'asr' => '15:15', 'maghrib' => '18:01', 'isha' => '19:10',
            ],
            'prayer_datetimes' => [
                'fajr' => '2026-10-15T04:40:00+07:00', 'sunrise' => '2026-10-15T05:55:00+07:00',
                'dhuhr' => '2026-10-15T11:59:00+07:00', 'asr' => '2026-10-15T15:15:00+07:00',
                'maghrib' => '2026-10-15T18:01:00+07:00', 'isha' => '2026-10-15T19:10:00+07:00',
            ],
        ],
    ];
}

function globalAladhanDayPayload(): array
{
    return [
        'code' => 200,
        'status' => 'OK',
        'data' => [
            'timings' => [
                'Fajr' => '04:40', 'Sunrise' => '05:55', 'Dhuhr' => '11:59',
                'Asr' => '15:15', 'Maghrib' => '18:01', 'Isha' => '19:10', 'Imsak' => '04:30',
            ],
            'date' => ['gregorian' => ['date' => '15-10-2026']],
            'meta' => ['method' => ['id' => 3]],
        ],
    ];
}

it('resolves global anchors from warm daily cache in the query timezone', function () {
    Http::fake();
    app(PrayerTimesCache::class)->putDaily(
        'ummah',
        'ID:Asia/Jakarta:ummah:MuslimWorldLeague:Shafi:-6.20:106.80',
        '2026-10-15',
        prayerCacheDto('2026-10-15', 'ummah:MuslimWorldLeague/Shafi')
    );

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(globalQuery(), PrayerReference::Maghrib);

    // Seeded DTO maghrib is 11:02 UTC → 18:02 Jakarta.
    expect($anchor['clock'])->toBe('18:02')
        ->and($anchor['source'])->toBe('ummah:MuslimWorldLeague/Shafi')
        ->and($anchor['stale'])->toBeFalse();

    Http::assertNothingSent();
});

it('fetches global daily times live from Ummah', function () {
    Bus::fake();
    Http::fake(['ummahapi.com/*' => Http::response(globalUmmahDayPayload())]);

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(globalQuery(), PrayerReference::Maghrib, true);

    expect($anchor['clock'])->toBe('18:01')
        ->and($anchor['source'])->toBe('ummah:MuslimWorldLeague/Shafi');

    Bus::assertNothingDispatched();
});

it('falls through to Aladhan when Ummah fails live', function () {
    Bus::fake();
    Http::fake([
        'ummahapi.com/*' => Http::response([], 500),
        'api.aladhan.com/*' => Http::response(globalAladhanDayPayload()),
    ]);

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(globalQuery(), PrayerReference::Maghrib, true);

    expect($anchor['clock'])->toBe('18:01')
        ->and($anchor['source'])->toBe('aladhan:3/Shafi');
});

it('agrees between live preview and cache-only reads when clock-only isha rolls into the next evening', function () {
    Bus::fake();
    $ummah = globalUmmahDayPayload();
    unset($ummah['data']['prayer_datetimes']);
    $ummah['data']['prayer_times']['maghrib'] = '19:00';
    $ummah['data']['prayer_times']['isha'] = '18:00';
    $aladhan = globalAladhanDayPayload();
    $aladhan['data']['timings']['Maghrib'] = '19:00';
    $aladhan['data']['timings']['Isha'] = '18:00';
    Http::fake([
        'ummahapi.com/*' => Http::response($ummah),
        'api.aladhan.com/*' => Http::response($aladhan),
    ]);

    $cache = app(PrayerTimesCache::class);

    // Live preview rejects both malformed rows instead of serving them.
    expect(app(ResolvePrayerAnchorAction::class)->handle(globalQuery(), PrayerReference::Isha, true))->toBeNull();

    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);

    // Nothing cacheable was stored: cache-only submission agrees.
    $query = globalQuery();

    expect($cache->getDaily('ummah', $cache->dailyCell($query, app(UmmahPrayerProvider::class)), '2026-10-15'))->toBeNull()
        ->and($cache->getDaily('aladhan', $cache->dailyCell($query, app(AladhanPrayerProvider::class)), '2026-10-15'))->toBeNull()
        ->and(app(ResolvePrayerAnchorAction::class)->handle($query, PrayerReference::Isha))->toBeNull();
});

it('falls through to Ummah when the JAKIM echo is malformed', function () {
    Bus::fake();
    $jakim = refreshV2Payload('WLY01');
    $jakim['zone'] = [];
    Http::fake([
        'api.waktusolat.app/*' => Http::response($jakim),
        'ummahapi.com/*' => Http::response([
            'success' => true,
            'data' => [
                'month' => 10,
                'year' => 2026,
                'days' => [[
                    'date' => '2026-10-15',
                    'day_start' => '2026-10-15T00:00:00+08:00',
                    'day_end' => '2026-10-16T00:00:00+08:00',
                    'hijri_date' => '1448-05-04',
                    'prayer_times' => [
                        'imsak' => '05:30', 'fajr' => '05:40', 'sunrise' => '06:58',
                        'dhuhr' => '12:59', 'asr' => '16:17', 'maghrib' => '19:01', 'isha' => '20:10',
                    ],
                ]],
            ],
        ]),
    ]);

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib, true);

    expect($anchor)->not->toBeNull()
        ->and($anchor['clock'])->toBe('19:01')
        ->and(str_starts_with((string) $anchor['source'], 'ummah:'))->toBeTrue();
});

it('returns null on a cold global cache and queues a refresh', function () {
    Bus::fake();

    $query = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8);

    expect(app(ResolvePrayerAnchorAction::class)->handle($query, PrayerReference::Maghrib))->toBeNull();

    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);
});

function zonedAladhanDayPayload(): array
{
    return [
        'code' => 200,
        'status' => 'OK',
        'data' => [
            'timings' => [
                'Fajr' => '05:55', 'Sunrise' => '07:05', 'Dhuhr' => '13:10',
                'Asr' => '16:35', 'Maghrib' => '19:05', 'Isha' => '20:15', 'Imsak' => '05:45',
            ],
            'date' => ['gregorian' => ['date' => '15-10-2026']],
            'meta' => ['timezone' => 'Asia/Kuala_Lumpur', 'method' => ['id' => 17]],
        ],
    ];
}

it('falls through to Aladhan daily times when zoned monthly providers fail', function () {
    Bus::fake();
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response([], 500),
        'api.aladhan.com/*' => Http::response(zonedAladhanDayPayload()),
    ]);

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib, true);

    expect($anchor['clock'])->toBe('19:05')
        ->and($anchor['source'])->toBe('aladhan:17/Shafi')
        ->and($anchor['stale'])->toBeFalse();

    // Ummah's failed live day re-queues; the served month dispatches nothing.
    Bus::assertDispatchedAfterResponse(
        RefreshPrayerTimes::class,
        fn (RefreshPrayerTimes $job): bool => $job->kind === 'daily'
            && ($job->scope['provider'] ?? null) === 'ummah'
    );
    Bus::assertNotDispatched(
        RefreshPrayerTimes::class,
        fn (RefreshPrayerTimes $job): bool => $job->kind === 'zone-month'
    );

    // The fallthrough warms the daily cell: a cache-only resolve serves it.
    $again = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib);

    expect($again['clock'])->toBe('19:05')
        ->and($again['source'])->toBe('aladhan:17/Shafi');
});

it('serves stale data on live failure while respecting negative markers', function () {
    Bus::fake();
    Http::fake([
        'ummahapi.com/*' => Http::response([], 500),
        'api.aladhan.com/*' => Http::response([], 500),
    ]);

    $cache = app(PrayerTimesCache::class);
    $cell = 'ID:Asia/Jakarta:aladhan:3:Shafi:-6.20:106.80';

    // Aladhan is marked failed but retains stale coverage: live and refresh
    // must skip it while stale still serves.
    $cache->putDaily('aladhan', $cell, '2026-10-15', prayerCacheDto('2026-10-15', 'aladhan:3/Shafi'));
    $cache->forget($cache->dailyKey('aladhan', $cell, '2026-10-15'));
    $cache->putNegative($cache->dailyKey('aladhan', $cell, '2026-10-15'));

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(globalQuery(), PrayerReference::Maghrib, true);

    // Fixture maghrib 11:02 UTC renders 18:02 in Jakarta.
    expect($anchor['clock'])->toBe('18:02')
        ->and($anchor['source'])->toBe('aladhan:3/Shafi')
        ->and($anchor['stale'])->toBeTrue();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.aladhan.com'));

    Bus::assertDispatchedAfterResponse(
        RefreshPrayerTimes::class,
        fn (RefreshPrayerTimes $job): bool => ($job->scope['provider'] ?? null) === 'ummah'
    );
    Bus::assertNotDispatched(
        RefreshPrayerTimes::class,
        fn (RefreshPrayerTimes $job): bool => ($job->scope['provider'] ?? null) === 'aladhan'
    );
});

it('separates daily cells by country and effective calculation settings', function () {
    Bus::fake();
    Http::fake();

    $cache = app(PrayerTimesCache::class);
    $cache->putDaily('ummah', 'ID:Asia/Jakarta:ummah:MuslimWorldLeague:Shafi:-6.20:106.80', '2026-10-15', prayerCacheDto());

    $action = app(ResolvePrayerAnchorAction::class);

    // Same identity: hit. Fixture maghrib 11:02 UTC renders 18:02 Jakarta.
    $same = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8, null, 'MuslimWorldLeague', 'Shafi');
    expect($action->handle($same, PrayerReference::Maghrib)['clock'])->toBe('18:02');

    // Different method, same coords: miss, never a cross-read.
    $method = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8, null, 'ISNA', 'Shafi');
    expect($action->handle($method, PrayerReference::Maghrib))->toBeNull();

    // Different country, same coords and settings: miss.
    $country = new PrayerQuery('MY', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8, null, 'MuslimWorldLeague', 'Shafi');
    expect($action->handle($country, PrayerReference::Maghrib))->toBeNull();

    Http::assertNothingSent();
});

it('prefers warm daily data over live fallback on zoned lookups', function () {
    Bus::fake();
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response([], 500),
        'api.aladhan.com/*' => Http::response(zonedAladhanDayPayload()),
    ]);

    // Warm Ummah daily cell for the zoned query coords + effective settings.
    app(PrayerTimesCache::class)->putDaily(
        'ummah',
        'MY:Asia/Kuala_Lumpur:ummah:malaysia:Shafi:3.14:101.69',
        '2026-10-15',
        prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi')
    );

    $live = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib, true);

    expect($live['clock'])->toBe('19:02')
        ->and($live['source'])->toBe('ummah:malaysia/Shafi');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.aladhan.com'));

    // Cache-only submission reads the identical entry: preview/submit parity.
    $cold = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib);

    expect($cold)->toBe($live);
});

it('misses cached Aladhan days when only its configured method changes', function () {
    Bus::fake();
    Http::fake();

    app(PrayerTimesCache::class)->putDaily(
        'aladhan',
        'ID:Asia/Jakarta:aladhan:3:Shafi:-6.20:106.80',
        '2026-10-15',
        prayerCacheDto()
    );

    $action = app(ResolvePrayerAnchorAction::class);

    // Control: default method 3 hits.
    expect($action->handle(globalQuery(), PrayerReference::Maghrib)['clock'])->toBe('18:02');

    // Only the numeric method changes: the old entry must not serve.
    config(['prayer.methods.default.aladhan' => 4]);

    expect($action->handle(globalQuery(), PrayerReference::Maghrib))->toBeNull();

    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);
});

it('falls back to stale instead of serving a wrong-day fresh row', function () {
    Bus::fake();
    Http::fake();
    $cache = app(PrayerTimesCache::class);

    // Fresh layer holds a pre-validation row: date D, maghrib on D-1.
    $base = prayerCacheDto('2026-10-15');
    $times = $base->timesUtc;
    $times['maghrib'] = CarbonImmutable::parse('2026-10-14 11:02:00', 'UTC');
    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    )], 'MY');

    // Stale layer holds a valid row with a distinguishable anchor.
    $valid = prayerCacheDto('2026-10-15');
    $validTimes = $valid->timesUtc;
    $validTimes['maghrib'] = CarbonImmutable::parse('2026-10-15 11:12:00', 'UTC');
    $cache->putMonthly('SCRATCH', '2026-10', ['2026-10-15' => new PrayerTimesDTO(
        timesUtc: $validTimes,
        source: $valid->source,
        fetchedAt: $valid->fetchedAt,
        timezoneUsed: $valid->timezoneUsed,
        date: $valid->date,
        zoneOrCell: $valid->zoneOrCell,
    )], 'MY');
    Cache::store()->put(
        $cache->staleMonthlyKey('WLY01', '2026-10', 'MY'),
        Cache::store()->get($cache->staleMonthlyKey('SCRATCH', '2026-10', 'MY')),
        3600,
    );

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(anchorQuery(), PrayerReference::Maghrib);

    // The invalid instant never serves: stale answers, refresh heals.
    expect($anchor['clock'])->toBe('19:12')
        ->and($anchor['stale'])->toBeTrue();

    Bus::assertDispatchedAfterResponse(RefreshPrayerTimes::class);
    Http::assertNothingSent();
});
