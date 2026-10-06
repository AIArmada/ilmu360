<?php

use App\Actions\Prayer\ResolvePrayerAnchorAction;
use App\Data\Prayer\PrayerQuery;
use App\Enums\PrayerReference;
use App\Jobs\RefreshPrayerTimes;
use App\Jobs\ResolveGpsZone;
use App\Services\Prayer\AladhanPrayerProvider;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\PrayerTimesCache;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Prayer\FailingPrayerCacheStore;
use Tests\Support\Prayer\ThrowingTestQueue;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

it('fills the monthly cache from the mirror without throwing', function () {
    Http::fake(['api.waktusolat.app/*' => Http::response(refreshV2Payload())]);

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    $days = app(PrayerTimesCache::class)->getMonthly('WLY01', '2026-10', 'MY');

    expect($days['2026-10-15']?->source)->toBe('jakim:v2/WLY01');
});

it('records a negative marker when the month is unpublished', function () {
    Http::fake([
        'api.waktusolat.app/*' => Http::response(['message' => 'No data found'], 404),
        'ummahapi.com/*' => Http::response(['message' => 'No data found'], 404),
    ]);

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-11', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    $cache = app(PrayerTimesCache::class);

    expect($cache->getMonthly('WLY01', '2026-11', 'MY'))->toBeNull()
        ->and($cache->hasNegative($cache->monthlyKey('WLY01', '2026-11', 'MY')))->toBeTrue();
});

it('falls through to Ummah monthly when the mirror fails', function () {
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response(refreshUmmahMonthPayload()),
    ]);

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    $days = app(PrayerTimesCache::class)->getMonthly('WLY01', '2026-10', 'MY');

    expect($days['2026-10-15']?->source)->toBe('ummah:malaysia/Shafi')
        ->and($days['2026-10-15']?->zoneOrCell)->toBe('WLY01');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'ummahapi.com')
            && (float) $request['lat'] === 3.1390
            && (float) $request['lng'] === 101.6869;
    });
});

it('self-heals a fallback-filled month back to the primary source', function () {
    Http::fake(['api.waktusolat.app/*' => Http::response(refreshV2Payload())]);
    $cache = app(PrayerTimesCache::class);
    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi'))], 'MY');

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), $cache);

    expect($cache->getMonthly('WLY01', '2026-10', 'MY')['2026-10-15']?->source)->toBe('jakim:v2/WLY01');
});

it('skips unknown zones and already-warm months without HTTP', function () {
    Http::fake(['api.waktusolat.app/*' => Http::response(refreshV2Payload())]);
    $cache = app(PrayerTimesCache::class);
    $cache->putMonthly('WLY01', '2026-10', prayerCompleteMonth('2026-10'), 'MY');

    (new RefreshPrayerTimes('zone-month', ['zone' => 'NOPE1', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), $cache);

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), $cache);

    Http::assertNothingSent();
});

it('skips zone-month refresh for coordinate-only countries without HTTP', function () {
    Http::fake();

    (new RefreshPrayerTimes('zone-month', [
        'zone' => 'XX01',
        'year_month' => '2026-10',
        'country' => 'XX',
        'timezone' => 'UTC',
    ]))->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    expect(app(PrayerTimesCache::class)->getMonthly('XX01', '2026-10', 'MY'))->toBeNull();

    Http::assertNothingSent();
});

it('refreshes zone-months with an explicit country scope', function () {
    Http::fake(['api.waktusolat.app/*' => Http::response(refreshV2Payload())]);

    (new RefreshPrayerTimes('zone-month', [
        'zone' => 'WLY01',
        'year_month' => '2026-10',
        'country' => 'MY',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]))->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    expect(app(PrayerTimesCache::class)->getMonthly('WLY01', '2026-10', 'MY')['2026-10-15']?->source)
        ->toBe('jakim:v2/WLY01');
});

it('warms shared zone months with the canonical country timezone, ignoring stale scope timezones', function () {
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response(refreshUmmahMonthPayload()),
    ]);

    // A stale queued payload (or older dispatcher) carrying a requester
    // timezone must not poison the shared month: the job derives the
    // canonical country timezone itself, matching preview/prefetch.
    (new RefreshPrayerTimes('zone-month', [
        'zone' => 'WLY01',
        'year_month' => '2026-10',
        'country' => 'MY',
        'timezone' => 'America/New_York',
    ]))->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/prayer-times/month')
            && $request['timezone'] === 'Asia/Kuala_Lumpur';
    });

    $days = app(PrayerTimesCache::class)->getMonthly('WLY01', '2026-10', 'MY');

    // Fixture maghrib 19:01+08:00 lands 11:01Z — no requester-tz shift.
    expect($days['2026-10-15']?->timeFor('maghrib')?->format('Y-m-d H:i'))->toBe('2026-10-15 11:01');
});

it('warms a second zoned country with its own configured timezone', function () {
    config([
        'prayer.zone_resolvers.YY' => JakimZoneResolver::class,
        'prayer.country_timezones.YY' => 'Asia/Jakarta',
    ]);
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response(refreshUmmahMonthPayload()),
    ]);

    (new RefreshPrayerTimes('zone-month', [
        'zone' => 'WLY01',
        'year_month' => '2026-10',
        'country' => 'YY',
        'timezone' => 'America/New_York',
    ]))->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/prayer-times/month')
            && $request['timezone'] === 'Asia/Jakarta';
    });

    expect(app(PrayerTimesCache::class)->getMonthly('WLY01', '2026-10', 'YY')['2026-10-15']?->source)
        ->toBe('ummah:MuslimWorldLeague/Shafi');
});

it('memos GPS zones and skips memoized coordinates', function () {
    Http::fake(['api.waktusolat.app/*' => Http::response(['zone' => 'WLY01', 'state' => 'KUL', 'district' => 'W.P. Kuala Lumpur'])]);

    $zones = app(JakimZoneResolver::class);

    (new ResolveGpsZone(3.1390, 101.6869))->handle(app(Factory::class), $zones);

    expect($zones->memoizedZone(3.1390, 101.6869))->toBe('WLY01');

    Http::assertSentCount(1);

    (new ResolveGpsZone(3.1390, 101.6869))->handle(app(Factory::class), $zones);

    Http::assertSentCount(1);
});

it('drops both refresh kinds without throwing when the prayer store is down', function () {
    useFailingPrayerCacheStore();
    Http::preventStrayRequests();

    $zoneMonth = new RefreshPrayerTimes('zone-month', [
        'zone' => 'WLY01',
        'year_month' => '2026-10',
        'country' => 'MY',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    $daily = new RefreshPrayerTimes('daily', [
        'provider' => 'aladhan',
        'cell' => 'cell-1',
        'date' => '2026-10-15',
        'country' => 'ID',
        'lat' => -6.2,
        'lng' => 106.8,
        'timezone' => 'Asia/Jakarta',
    ]);

    // Lock acquisition itself throws on a dead store; the handle catch
    // degrades instead of propagating onto request termination.
    expect(fn () => $zoneMonth->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class)))->not->toThrow(Throwable::class)
        ->and(fn () => $daily->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class)))->not->toThrow(Throwable::class);

    config(['prayer.cache.store' => null]);
});

it('fills a partial primary month from the secondary without clobbering primary days', function () {
    Http::fake([
        'api.waktusolat.app/*' => Http::response(refreshV2Payload()),
        'ummahapi.com/*' => Http::response(refreshUmmahMonthPayload()),
    ]);

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), app(PrayerTimesCache::class));

    // Mirror carries day 15 only; Ummah carries 14+15. The overlap keeps
    // the primary source while the gap fills from the secondary.
    $days = app(PrayerTimesCache::class)->getMonthly('WLY01', '2026-10', 'MY');

    expect($days['2026-10-15']?->source)->toBe('jakim:v2/WLY01')
        ->and($days['2026-10-14']?->source)->toBe('ummah:malaysia/Shafi');
});

it('preserves complete stale coverage when the refresh is partial', function () {
    Http::fake([
        'api.waktusolat.app/*' => Http::response(refreshV2Payload()),
        'ummahapi.com/*' => Http::response([], 500),
    ]);
    $cache = app(PrayerTimesCache::class);

    // Complete stale month whose fresh layer expired.
    $cache->putMonthly('WLY01', '2026-10', prayerCompleteMonth('2026-10'), 'MY');
    $cache->forget($cache->monthlyKey('WLY01', '2026-10', 'MY'));

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), $cache);

    // The single-day fresh fetch merges; no stale day is wiped.
    $stale = $cache->getStaleMonthly('WLY01', '2026-10', 'MY');

    expect($stale)->toHaveCount(31)
        ->and($stale['2026-10-14']?->source)->toBe('jakim:v2/WLY01')
        ->and($cache->getMonthly('WLY01', '2026-10', 'MY')['2026-10-14'] ?? null)->toBeNull();
});

it('keeps primary coverage across complementary partial refreshes', function () {
    $cache = app(PrayerTimesCache::class);

    // Sequences: a second Http::fake() call merges stubs (first match
    // wins), so per-run payloads must be sequenced, not re-faked.
    $jakim14 = refreshV2Payload();
    $day14 = array_merge($jakim14['prayers'][0], ['day' => 14]);

    // Genuine day-14 epochs: absolute stamps must land on the row's own
    // prayer day, so a relabeled row shifts its clocks by a full day.
    foreach (['imsak', 'fajr', 'syuruk', 'dhuha', 'dhuhr', 'asr', 'maghrib', 'isha'] as $field) {
        $day14[$field] -= 86400;
    }

    $jakim14['prayers'] = [$day14];

    Http::fake([
        // Run 1: mirror carries day 15 only; run 2: day 14 only.
        'api.waktusolat.app/*' => Http::sequence()
            ->push(refreshV2Payload())
            ->push($jakim14),
        // Run 1: secondary down; run 2: secondary carries both days.
        'ummahapi.com/*' => Http::sequence()
            ->push([], 500)
            ->push(refreshUmmahMonthPayload()),
    ]);

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), $cache);

    (new RefreshPrayerTimes('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']))
        ->handle(app(PrayerProviderRegistry::class), $cache);

    // The secondary overlap must not replace retained primary day 15.
    $days = $cache->getMonthly('WLY01', '2026-10', 'MY');

    expect($days['2026-10-15']?->source)->toBe('jakim:v2/WLY01')
        ->and($days['2026-10-14']?->source)->toBe('jakim:v2/WLY01');
});

it('dispatches both jobs for real with a failing default cache store', function () {
    Cache::extend('failing-default-store', fn (): Repository => new Repository(new FailingPrayerCacheStore));
    config([
        'cache.stores.failing-default-store' => ['driver' => 'failing-default-store'],
        'cache.default' => 'failing-default-store',
        // Prayer owns its store end to end: pinned healthy while the
        // default store is down.
        'prayer.cache.store' => 'array',
        'queue.default' => 'sync',
    ]);

    Http::fake([
        '*/zones/*' => Http::response(['zone' => 'WLY01']),
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response([], 500),
    ]);

    // Real sync dispatch (no Bus fake): per-scope prayer-store locks keep
    // both jobs working while the default store is down. ShouldBeUnique
    // would throw here acquiring its unique lock on the dead store.
    RefreshPrayerTimes::dispatch('zone-month', ['zone' => 'WLY01', 'year_month' => '2026-10', 'country' => 'MY']);
    ResolveGpsZone::dispatch(3.139, 101.6869);

    // Both jobs executed against the healthy prayer store.
    $cache = app(PrayerTimesCache::class);

    expect($cache->hasNegative($cache->monthlyKey('WLY01', '2026-10', 'MY')))->toBeTrue()
        ->and(app(JakimZoneResolver::class)->memoizedZone(3.139, 101.6869))->toBe('WLY01');

    config(['cache.default' => 'array', 'prayer.cache.store' => null]);
});

it('schedules a delayed async retry when the GPS lookup fails on a redis-backed queue', function () {
    config(['queue.default' => 'redis']);
    Queue::fake();
    Http::fake(['api.waktusolat.app/*' => Http::response([], 500)]);

    // Real handle execution (as the worker runs it); only the transport
    // is faked. release() on the after-response sync run would drop the
    // retry silently — the fix enqueues a fresh delayed attempt instead.
    (new ResolveGpsZone(3.139, 101.6869))->handle(app(Factory::class), app(JakimZoneResolver::class));

    Queue::assertPushed(ResolveGpsZone::class, fn (ResolveGpsZone $job): bool => $job->attempt === 2
        && $job->latitude === 3.139
        && $job->longitude === 101.6869);

    expect(Queue::delayedJobs()->firstWhere('name', ResolveGpsZone::class))->not->toBeNull()
        ->and(app(JakimZoneResolver::class)->memoizedZone(3.139, 101.6869))->toBeNull();
});

it('bounds real after-response GPS retries on the sync connection', function () {
    config(['queue.default' => 'sync']);
    Http::fake(['api.waktusolat.app/*' => Http::response([], 500)]);
    Log::spy();

    ResolveGpsZone::dispatchAfterResponse(3.139, 101.6869);

    // Fire the terminating callbacks exactly as the kernel does after the
    // response: dispatchAfterResponse forces the sync connection, so the
    // retry nests inline — and must stop at the attempt bound.
    app()->terminate();

    Http::assertSentCount(3);

    expect(app(JakimZoneResolver::class)->memoizedZone(3.139, 101.6869))->toBeNull();

    Log::shouldHaveReceived('warning', ['GPS zone lookup exhausted retries; the next lookup will re-trigger.', Mockery::any()]);
});

it('stops redispatching once GPS attempts are exhausted', function () {
    Queue::fake();
    Http::fake(['api.waktusolat.app/*' => Http::response([], 500)]);

    (new ResolveGpsZone(3.139, 101.6869, 3))->handle(app(Factory::class), app(JakimZoneResolver::class));

    Http::assertSentCount(1);

    Queue::assertNotPushed(ResolveGpsZone::class);
});

it('discards a deferred daily refresh whose calculation settings drifted', function () {
    Bus::fake();
    config(['prayer.methods.default.aladhan' => 3]);

    $cache = app(PrayerTimesCache::class);
    $aladhan = app(AladhanPrayerProvider::class);

    // Dispatch-time cell under method 3.
    $dispatchQuery = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8, null, null, 'Shafi');
    $cell = $cache->dailyCell($dispatchQuery, $aladhan);

    expect($cell)->toBe('ID:Asia/Jakarta:aladhan:3:Shafi:-6.20:106.80');

    // Settings change before the worker runs.
    config(['prayer.methods.default.aladhan' => 4]);

    Http::fake(['api.aladhan.com/*' => Http::response([
        'code' => 200,
        'status' => 'OK',
        'data' => [
            'timings' => [
                'Fajr' => '04:40',
                'Sunrise' => '05:55',
                'Dhuhr' => '11:45',
                'Asr' => '15:00',
                'Maghrib' => '17:55',
                'Isha' => '19:05',
            ],
            'date' => ['gregorian' => ['date' => '15-10-2026']],
            'meta' => ['timezone' => 'Asia/Jakarta'],
        ],
    ])]);

    (new RefreshPrayerTimes('daily', [
        'provider' => 'aladhan',
        'cell' => $cell,
        'date' => '2026-10-15',
        'country' => 'ID',
        'lat' => -6.2,
        'lng' => 106.8,
        'timezone' => 'Asia/Jakarta',
        'method' => null,
        'madhab' => 'Shafi',
    ]))->handle(app(PrayerProviderRegistry::class), $cache);

    // Discarded before any fetch: the method-3 key holds no method-4
    // result a rollback or older worker could serve.
    Http::assertNothingSent();

    expect($cache->getDaily('aladhan', $cell, '2026-10-15'))->toBeNull();

    // Restored config still resolves nothing from that key.
    config(['prayer.methods.default.aladhan' => 3]);

    expect(app(ResolvePrayerAnchorAction::class)->handle($dispatchQuery, PrayerReference::Maghrib, false))->toBeNull();
});

it('lets later warmers run when GPS retry dispatch fails', function () {
    config(['queue.default' => 'throwing', 'queue.connections.throwing' => ['driver' => 'throwing']]);
    app('queue')->extend('throwing', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new ThrowingTestQueue;
        }
    });
    config(['prayer.methods.default.aladhan' => 3]);
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'api.aladhan.com/*' => Http::response([
            'code' => 200,
            'status' => 'OK',
            'data' => [
                'timings' => [
                    'Fajr' => '04:40',
                    'Sunrise' => '05:55',
                    'Dhuhr' => '11:45',
                    'Asr' => '15:00',
                    'Maghrib' => '17:55',
                    'Isha' => '19:05',
                ],
                'date' => ['gregorian' => ['date' => '15-10-2026']],
                'meta' => ['timezone' => 'Asia/Jakarta'],
            ],
        ]),
    ]);
    Log::spy();

    $cache = app(PrayerTimesCache::class);
    $dispatchQuery = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8, null, null, 'Shafi');
    $cell = $cache->dailyCell($dispatchQuery, app(AladhanPrayerProvider::class));

    // GPS first (as the submit path schedules it), warmer second — both
    // real after-response callbacks, throwing retry transport.
    Bus::dispatchAfterResponse(new ResolveGpsZone(3.139, 101.6869));
    Bus::dispatchAfterResponse(new RefreshPrayerTimes('daily', [
        'provider' => 'aladhan',
        'cell' => $cell,
        'date' => '2026-10-15',
        'country' => 'ID',
        'lat' => -6.2,
        'lng' => 106.8,
        'timezone' => 'Asia/Jakarta',
        'method' => null,
        'madhab' => 'Shafi',
    ]));

    // Real termination loop: the failed retry dispatch must not cancel
    // the warmer queued behind it.
    app()->terminate();

    expect($cache->getDaily('aladhan', $cell, '2026-10-15')?->source)->toBe('aladhan:3/Shafi');

    Log::shouldHaveReceived('warning', ['GPS zone retry dispatch failed; the next lookup will re-trigger.', Mockery::any()]);
});

it('lets later warmers run when GPS returns a malformed zone', function () {
    config(['prayer.methods.default.aladhan' => 3]);
    Http::fake([
        'api.waktusolat.app/*' => Http::response(['zone' => []], 200),
        'api.aladhan.com/*' => Http::response([
            'code' => 200,
            'status' => 'OK',
            'data' => [
                'timings' => [
                    'Fajr' => '04:40',
                    'Sunrise' => '05:55',
                    'Dhuhr' => '11:45',
                    'Asr' => '15:00',
                    'Maghrib' => '17:55',
                    'Isha' => '19:05',
                ],
                'date' => ['gregorian' => ['date' => '15-10-2026']],
                'meta' => ['timezone' => 'Asia/Jakarta'],
            ],
        ]),
    ]);
    Log::spy();

    $cache = app(PrayerTimesCache::class);
    $dispatchQuery = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8, null, null, 'Shafi');
    $cell = $cache->dailyCell($dispatchQuery, app(AladhanPrayerProvider::class));

    // GPS first (as the submit path schedules it), warmer second — both
    // real after-response callbacks, array-valued zone response.
    Bus::dispatchAfterResponse(new ResolveGpsZone(3.139, 101.6869));
    Bus::dispatchAfterResponse(new RefreshPrayerTimes('daily', [
        'provider' => 'aladhan',
        'cell' => $cell,
        'date' => '2026-10-15',
        'country' => 'ID',
        'lat' => -6.2,
        'lng' => 106.8,
        'timezone' => 'Asia/Jakarta',
        'method' => null,
        'madhab' => 'Shafi',
    ]));

    // Real termination loop: the malformed zone must be contained, not
    // escape as an exception that cancels the warmer queued behind it.
    app()->terminate();

    expect($cache->getDaily('aladhan', $cell, '2026-10-15')?->source)->toBe('aladhan:3/Shafi');

    $gpsCalls = Http::recorded()->filter(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/zones/')
    );

    // Deterministic stop: no retry storm on a malformed success payload.
    expect($gpsCalls)->toHaveCount(1);

    Log::shouldHaveReceived('warning', ['GPS zone lookup returned a malformed zone.', Mockery::any()]);
});
