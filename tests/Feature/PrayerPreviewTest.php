<?php

use App\Services\Prayer\HardcodedPrayerFallback;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerTimesCache;
use Illuminate\Support\Facades\Http;

it('previews exact start clocks from warm cache', function () {
    Http::fake();
    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $response = $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-10-15&zone=WLY01');

    $response->assertOk();
    $data = $response->json('data');

    // Fixture maghrib anchor 19:02 MYT + Immediately (+5) => 19:07.
    expect($data['zone'])->toBe('WLY01')
        ->and($data['source'])->toBe('jakim:v2/WLY01')
        ->and($data['exact'])->toBeTrue()
        ->and($data['starts']['selepas_maghrib'])->toBe('19:07')
        ->and($data['starts']['selepas_subuh'])->toBe('05:55')
        ->and($data['starts']['selepas_tarawih'])->toBe(HardcodedPrayerFallback::MAP['selepas_tarawih']);

    $response->assertHeader('X-Prayer-Source', 'jakim:v2/WLY01');
    Http::assertNothingSent();
});

it('resolves the zone from coordinates when no zone is given', function () {
    app(PrayerTimesCache::class)->putMonthly('SGR01', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');
    app(JakimZoneResolver::class)->rememberZone(3.0733, 101.5185, 'SGR01');

    $response = $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-10-15&lat=3.0733&lng=101.5185');

    $response->assertOk();
    expect($response->json('data.zone'))->toBe('SGR01')
        ->and($response->json('data.location_source'))->toBe('gps-memo');
});

it('degrades to hardcoded estimates when the mirror is unpublished', function () {
    Http::fake([
        'api.waktusolat.app/*' => Http::response(['message' => 'No data found'], 404),
        'ummahapi.com/*' => Http::response(['message' => 'No data found'], 404),
    ]);

    $response = $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-10-15&zone=WLY01');

    $response->assertOk();
    $data = $response->json('data');

    expect($data['exact'])->toBeFalse()
        ->and($data['source'])->toBe(HardcodedPrayerFallback::SOURCE)
        ->and($data['starts']['selepas_maghrib'])->toBe('20:00');
});

it('serves hardcoded hints when the cache store is down', function () {
    useFailingPrayerCacheStore();
    Http::fake([
        'api.waktusolat.app/*' => Http::response(['message' => 'No data found'], 404),
        'ummahapi.com/*' => Http::response(['message' => 'No data found'], 404),
    ]);

    $response = $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-10-15&zone=WLY01');

    $response->assertOk();
    $data = $response->json('data');

    expect($data['exact'])->toBeFalse()
        ->and($data['source'])->toBe(HardcodedPrayerFallback::SOURCE)
        ->and($data['starts']['selepas_maghrib'])->toBe('20:00');

    config(['prayer.cache.store' => null]);
});

it('matches the zone offline from a district name', function () {
    Http::fake();
    app(PrayerTimesCache::class)->putMonthly('JHR04', '2026-10', ['2026-10-15' => prayerCacheDto()], 'MY');

    $response = $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-10-15&state=01&district=Muar');

    $response->assertOk();
    expect($response->json('data.zone'))->toBe('JHR04')
        ->and($response->json('data.location_source'))->toBe('district-match')
        ->and($response->json('data.exact'))->toBeTrue();
    Http::assertNothingSent();
});

it('names the problem in the unknown-zone message', function () {
    $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-10-15&zone=NOPE1')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unknown prayer zone code.');
});

it('rejects invalid dates and unknown zones', function () {
    $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-02-31&zone=WLY01')
        ->assertStatus(422);

    $this->getJson('/api/v1/catalogs/prayer-preview?date=2026-10-15&zone=NOPE1')
        ->assertStatus(422);

    $this->getJson('/api/v1/catalogs/prayer-preview?zone=WLY01')
        ->assertStatus(422);
});
