<?php

use App\Actions\Prayer\ResolvePrayerAnchorAction;
use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Data\Prayer\PrayerQuery;
use App\Data\Prayer\PrayerTimesDTO;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerReference;
use App\Services\Prayer\JakimMirrorProvider;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\PrayerTimesCache;
use App\Services\Prayer\UmmahPrayerProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function canonicalWly01Query(string $date = '2026-10-01'): PrayerQuery
{
    $registry = app(PrayerProviderRegistry::class);
    $coords = $registry->zoneResolverFor('MY')->coordsForZone('WLY01');

    return new PrayerQuery('MY', $date, $registry->timezoneFor('MY'), $coords['lat'] ?? null, $coords['lng'] ?? null, 'WLY01');
}

it('round-trips monthly payloads through fresh and stale keys', function () {
    $cache = app(PrayerTimesCache::class);
    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    expect($cache->getMonthly('WLY01', '2026-10', 'MY')['2026-10-15']?->source)->toBe('jakim:v2/WLY01')
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY')['2026-10-15']?->timeFor('maghrib')?->format('H:i'))->toBe('11:02');

    $cache->forget($cache->monthlyKey('WLY01', '2026-10', 'MY'));

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->not->toBeNull();
});

it('scopes monthly keys per country so zones never collide', function () {
    $cache = app(PrayerTimesCache::class);

    expect($cache->monthlyKey('WLY01', '2026-10', 'MY'))
        ->toStartWith(config('prayer.cache.prefix', 'prayer:v3').':zone:MY:WLY01:2026-10')
        ->and($cache->monthlyKey('WLY01', '2026-10', 'XX'))
        ->not->toBe($cache->monthlyKey('WLY01', '2026-10', 'MY'));

    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'XX');

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getMonthly('WLY01', '2026-10', 'XX'))->not->toBeNull();
});

it('round-trips daily payloads', function () {
    $cache = app(PrayerTimesCache::class);
    $cache->putDaily('ummah', 'cell-1', '2026-10-15', prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi'));

    expect($cache->getDaily('ummah', 'cell-1', '2026-10-15')?->source)->toBe('ummah:malaysia/Shafi')
        ->and($cache->getStaleDaily('ummah', 'cell-1', '2026-10-15'))->not->toBeNull()
        ->and($cache->getDaily('ummah', 'cell-1', '2026-10-16'))->toBeNull();
});

it('tracks short negative markers', function () {
    $cache = app(PrayerTimesCache::class);
    $key = $cache->monthlyKey('WLY01', '2026-11', 'MY');

    expect($cache->hasNegative($key))->toBeFalse();

    $cache->putNegative($key);

    expect($cache->hasNegative($key))->toBeTrue();
});

it('rejects corrupt payloads instead of throwing', function () {
    $cache = app(PrayerTimesCache::class);
    Cache::store()->put($cache->monthlyKey('WLY01', '2026-10', 'MY'), ['2026-10-15' => ['nope' => true]], 60);
    Cache::store()->put($cache->dailyKey('ummah', 'cell-1', '2026-10-15'), 'garbage', 60);

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull();
});

it('degrades reads to misses and writes to no-ops when the store is down', function () {
    useFailingPrayerCacheStore();

    $cache = app(PrayerTimesCache::class);
    $key = $cache->monthlyKey('WLY01', '2026-10', 'MY');

    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');
    $cache->putDaily('ummah', 'cell-1', '2026-10-15', prayerCacheDto());
    $cache->putNegative($key);
    $cache->forget($key);

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull()
        ->and($cache->getStaleDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull()
        ->and($cache->hasNegative($key))->toBeFalse();

    config(['prayer.cache.store' => null]);
});

it('rejects months whose rows do not cover every calendar day', function () {
    $cache = app(PrayerTimesCache::class);
    $days = prayerCompleteMonth('2026-10');
    unset($days['2026-10-31']);
    $days['2026-11-01'] = prayerCacheDto('2026-11-01');

    expect($days)->toHaveCount(31)
        ->and($cache->isFullySourced($days, 'jakim:', '2026-10'))->toBeFalse()
        ->and($cache->isFullySourced(prayerCompleteMonth('2026-10'), 'jakim:', '2026-10'))->toBeTrue();
});

it('keeps higher-priority stored days across priority-aware merges', function () {
    $cache = app(PrayerTimesCache::class);
    $keys = ['jakim', 'ummah'];

    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-15')], 'MY', $keys);
    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-14' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-14', 'ummah:malaysia/Shafi')),
        '2026-10-15' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi')),
    ], 'MY', $keys);

    $days = $cache->getMonthly('WLY01', '2026-10', 'MY');

    expect($days['2026-10-15']?->source)->toBe('jakim:v2/WLY01')
        ->and($days['2026-10-14']?->source)->toBe('ummah:malaysia/Shafi');
});

it('serializes monthly merges under the per-scope merge lock', function () {
    $cache = app(PrayerTimesCache::class);
    $keys = ['jakim', 'ummah'];
    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-15')], 'MY', $keys);

    // Hold the merge lock: a concurrent secondary write must skip, never
    // clobber the stored primary day with its older snapshot.
    $store = Cache::store(config('prayer.cache.store'))->getStore();
    $lock = $store->lock('prayer:lock:zone-merge:MY:WLY01:2026-10', 10);

    expect($lock->acquire())->toBeTrue();

    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-14' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-14', 'ummah:malaysia/Shafi')),
        '2026-10-15' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi')),
    ], 'MY', $keys);

    $days = $cache->getMonthly('WLY01', '2026-10', 'MY');

    expect(array_keys($days ?? []))->toBe(['2026-10-15'])
        ->and($days['2026-10-15']?->source)->toBe('jakim:v2/WLY01');

    // Released: the complementary secondary day merges, primary intact.
    $lock->release();
    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-14' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-14', 'ummah:malaysia/Shafi')),
    ], 'MY', $keys);

    $days = $cache->getMonthly('WLY01', '2026-10', 'MY');

    expect($days['2026-10-14']?->source)->toBe('ummah:malaysia/Shafi')
        ->and($days['2026-10-15']?->source)->toBe('jakim:v2/WLY01');
});

it('prunes monthly days computed under superseded calculation settings', function () {
    $cache = app(PrayerTimesCache::class);
    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-14' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-14', 'ummah:malaysia/Shafi')),
        '2026-10-15' => prayerCacheDto('2026-10-15', 'jakim:v2/WLY01'),
    ], 'MY');

    // Control: under matching settings the whole mixed month serves.
    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toHaveCount(2);

    config(['prayer.methods.MY.madhab' => 'Hanafi']);

    // Only the obsolete Ummah day prunes; the setting-free JAKIM day and
    // the stale layer behave identically.
    $fresh = $cache->getMonthly('WLY01', '2026-10', 'MY');
    $stale = $cache->getStaleMonthly('WLY01', '2026-10', 'MY');

    expect(array_keys($fresh ?? []))->toBe(['2026-10-15'])
        ->and(array_keys($stale ?? []))->toBe(['2026-10-15']);
});

it('reads a fully obsolete month as a gap that refresh can heal', function () {
    $cache = app(PrayerTimesCache::class);
    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-15' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi')),
    ], 'MY');

    config(['prayer.methods.MY.madhab' => 'Hanafi']);

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->isFullySourced($cache->getMonthly('WLY01', '2026-10', 'MY'), 'ummah:', '2026-10'))->toBeFalse();
});

it('rejects cached rows whose instants fall off the prayer day', function () {
    $cache = app(PrayerTimesCache::class);

    // A pre-validation row: date D, maghrib instant on D-1 local.
    $base = prayerCacheDto('2026-10-15');
    $times = $base->timesUtc;
    $times['maghrib'] = CarbonImmutable::parse('2026-10-14 11:02:00', 'UTC');
    $poisoned = new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    );

    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-15' => $poisoned,
        '2026-10-16' => prayerCacheDto('2026-10-16'),
    ], 'MY');
    $cache->putDaily('ummah', 'cell-1', '2026-10-15', $poisoned);

    // Wrong-day rows degrade to misses on every layer; the valid
    // sibling day still serves.
    $fresh = $cache->getMonthly('WLY01', '2026-10', 'MY');
    $stale = $cache->getStaleMonthly('WLY01', '2026-10', 'MY');

    expect(array_keys($fresh ?? []))->toBe(['2026-10-16'])
        ->and(array_keys($stale ?? []))->toBe(['2026-10-16'])
        ->and($cache->getDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull()
        ->and($cache->getStaleDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull();
});

it('misses calculated monthly rows after representative coordinates change', function () {
    Http::fake(['ummahapi.com/*' => Http::response(refreshUmmahMonthPayload())]);
    $cache = app(PrayerTimesCache::class);

    $cache->putMonthly('WLY01', '2026-10', app(UmmahPrayerProvider::class)->monthlyPrayers(canonicalWly01Query()), 'MY');

    // Control: the warmed month serves on both layers.
    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toHaveCount(2)
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->toHaveCount(2);

    // Only the representative coordinates change: settings untouched.
    config(['prayer_zones.zone_coords.WLY01' => ['lat' => 1.5, 'lng' => 100.5]]);

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->toBeNull();
});

it('misses calculated monthly rows after the country timezone changes', function () {
    Http::fake(['ummahapi.com/*' => Http::response(refreshUmmahMonthPayload())]);
    $cache = app(PrayerTimesCache::class);

    $cache->putMonthly('WLY01', '2026-10', app(UmmahPrayerProvider::class)->monthlyPrayers(canonicalWly01Query()), 'MY');

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toHaveCount(2);

    config(['prayer.country_timezones.MY' => 'UTC']);

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->toBeNull();
});

it('keeps authoritative mirror rows across coordinate and timezone edits', function () {
    Http::fake(['api.waktusolat.app/*' => Http::response(refreshV2Payload())]);
    $cache = app(PrayerTimesCache::class);

    $cache->putMonthly('WLY01', '2026-10', app(JakimMirrorProvider::class)->monthlyPrayers(canonicalWly01Query()), 'MY');

    config(['prayer_zones.zone_coords.WLY01' => ['lat' => 1.5, 'lng' => 100.5]]);
    config(['prayer.country_timezones.MY' => 'UTC']);

    // Authority data, not calculated: calc inputs cannot obsolete it.
    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toHaveCount(1)
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->toHaveCount(1);
});

it('rejects cached rows filed under the wrong serving date', function () {
    $cache = app(PrayerTimesCache::class);

    // Internally consistent row, but filed under a neighboring key.
    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto('2026-10-16')], 'MY');
    $cache->putDaily('ummah', 'cell-1', '2026-10-15', prayerCacheDto('2026-10-16'));

    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull()
        ->and($cache->getStaleDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull();
});

it('heals poisoned primary rows through lower-priority refresh data', function () {
    Bus::fake();
    Http::fake();
    config()->set('prayer.enabled', true);

    $cache = app(PrayerTimesCache::class);
    $keys = ['jakim', 'ummah'];

    // Poisoned JAKIM row: maghrib instant off the prayer day.
    $base = prayerCacheDto('2026-10-15');
    $times = $base->timesUtc;
    $times['maghrib'] = CarbonImmutable::parse('2026-10-14 11:02:00', 'UTC');
    $poisoned = new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    );

    $cache->putMonthly('WLY01', '2026-10', ['2026-10-15' => $poisoned], 'MY', $keys);

    // Control: readers reject the poisoned row on both layers.
    expect($cache->getMonthly('WLY01', '2026-10', 'MY'))->toBeNull()
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY'))->toBeNull();

    // JAKIM unavailable; valid Ummah refresh arrives for the same day.
    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-15' => prayerWithCalcFingerprint(prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi')),
    ], 'MY', $keys);

    // Both layers heal to the valid fallback row.
    expect($cache->getMonthly('WLY01', '2026-10', 'MY')['2026-10-15']?->source)->toBe('ummah:malaysia/Shafi')
        ->and($cache->getStaleMonthly('WLY01', '2026-10', 'MY')['2026-10-15']?->source)->toBe('ummah:malaysia/Shafi');

    // Preview and submission agree on the healed row without live HTTP.
    app(JakimZoneResolver::class)->rememberZone(3.1390, 101.6869, 'WLY01');

    $anchor = app(ResolvePrayerAnchorAction::class)->handle(
        new PrayerQuery('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 3.1390, 101.6869, 'WLY01'),
        PrayerReference::Maghrib,
    );

    $start = app(ResolvePrayerStartClockAction::class)->handle(
        'MY', '2026-10-15', 'Asia/Kuala_Lumpur', EventPrayerTime::SelepasMaghrib, 3.1390, 101.6869,
    );

    expect($anchor['clock'])->toBe('19:02')
        ->and($anchor['source'])->toBe('ummah:malaysia/Shafi')
        ->and($start['starts_at'])->toBe(CarbonImmutable::parse('2026-10-15 19:07:00', 'Asia/Kuala_Lumpur')->utc()->toIso8601String())
        ->and($start['source'])->toBe('ummah:malaysia/Shafi');

    Http::assertNothingSent();
});

it('rejects cached rows whose same-day isha precedes maghrib', function () {
    $cache = app(PrayerTimesCache::class);

    // Same-day stamp, but the previous night's anchor: isha 00:30
    // against maghrib 19:02 local.
    $base = prayerCacheDto('2026-10-15');
    $times = $base->timesUtc;
    $times['isha'] = CarbonImmutable::parse('2026-10-14 16:30:00', 'UTC');
    $poisoned = new PrayerTimesDTO(
        timesUtc: $times,
        source: $base->source,
        fetchedAt: $base->fetchedAt,
        timezoneUsed: $base->timezoneUsed,
        date: $base->date,
        zoneOrCell: $base->zoneOrCell,
    );

    $cache->putMonthly('WLY01', '2026-10', [
        '2026-10-15' => $poisoned,
        '2026-10-16' => prayerCacheDto('2026-10-16'),
    ], 'MY');
    $cache->putDaily('ummah', 'cell-1', '2026-10-15', $poisoned);

    $fresh = $cache->getMonthly('WLY01', '2026-10', 'MY');
    $stale = $cache->getStaleMonthly('WLY01', '2026-10', 'MY');

    expect(array_keys($fresh ?? []))->toBe(['2026-10-16'])
        ->and(array_keys($stale ?? []))->toBe(['2026-10-16'])
        ->and($cache->getDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull()
        ->and($cache->getStaleDaily('ummah', 'cell-1', '2026-10-15'))->toBeNull();
});
