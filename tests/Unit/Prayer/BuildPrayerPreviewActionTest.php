<?php

use App\Actions\Prayer\BuildPrayerPreviewAction;
use App\Actions\Prayer\ResolvePrayerAnchorAction;
use App\Data\Prayer\PrayerQuery;
use App\Enums\PrayerReference;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerProviderRegistry;
use App\Services\Prayer\PrayerTimesCache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

function previewUmmahDayPayload(): array
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

it('uses explicit Labuan coordinates during fallback instead of Kuala Lumpur', function () {
    Bus::fake();
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response(refreshUmmahMonthPayload()),
    ]);

    $preview = app(BuildPrayerPreviewAction::class)->handle('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 'WLY02');

    expect($preview['zone'])->toBe('WLY02')
        ->and($preview['location_source'])->toBe('explicit-zone')
        ->and($preview['exact'])->toBeTrue();

    // Labuan town coordinates — never the KL country default.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'ummahapi.com')
            && (float) $request['lat'] === 5.28
            && (float) $request['lng'] === 115.24;
    });
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'ummahapi.com')
            && (float) $request['lat'] === 3.139
            && (float) $request['lng'] === 101.6869;
    });
});

it('warms the exact daily cells submission reads for global countries', function () {
    Bus::fake();
    Http::fake(['ummahapi.com/*' => Http::response(previewUmmahDayPayload())]);

    $preview = app(BuildPrayerPreviewAction::class)->handle('ID', '2026-10-15', 'Asia/Jakarta', null, -6.2, 106.8);

    expect($preview['exact'])->toBeTrue();

    // The submission path rebuilds the query from the same configured
    // settings; its cache-only read must hit the preview-warmed cell.
    $methods = app(PrayerProviderRegistry::class)->methodsFor('ID');
    $query = new PrayerQuery('ID', '2026-10-15', 'Asia/Jakarta', -6.2, 106.8, null, $methods['ummah'], $methods['madhab']);

    $anchor = app(ResolvePrayerAnchorAction::class)->handle($query, PrayerReference::Maghrib);

    expect($anchor['clock'])->toBe('18:01')
        ->and($anchor['source'])->toBe('ummah:MuslimWorldLeague/Shafi')
        ->and($anchor['stale'])->toBeFalse();

    Http::assertSentCount(1);
});

it('skips the daily fallback for explicit zones without representative coords', function () {
    Bus::fake();
    config(['prayer_zones.zones.TST01' => ['state' => 'XX', 'districts' => 'Test']]);
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => Http::response([], 500),
        'api.aladhan.com/*' => Http::response([], 500),
    ]);

    // Warm the KL country-default cell the old fallback would have read.
    app(PrayerTimesCache::class)->putDaily(
        'ummah',
        'MY:Asia/Kuala_Lumpur:ummah:malaysia:Shafi:3.14:101.69',
        '2026-10-15',
        prayerCacheDto('2026-10-15', 'ummah:malaysia/Shafi')
    );

    $preview = app(BuildPrayerPreviewAction::class)->handle('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 'TST01');

    expect($preview['zone'])->toBe('TST01')
        ->and($preview['exact'])->toBeFalse()
        ->and($preview['starts']['selepas_maghrib'])->toBe('20:00');
});

it('computes shared zone months from canonical inputs regardless of requester coords', function () {
    Bus::fake();

    // Memoize two distinct in-zone positions so both resolve WLY01 with
    // their own coordinates.
    $zones = app(JakimZoneResolver::class);
    $zones->rememberZone(3.00, 101.50, 'WLY01');
    $zones->rememberZone(3.20, 101.80, 'WLY01');

    // Mirror down; Ummah answers per requested coords so cross-reads show.
    $monthFor = function (string $maghribClock): array {
        $payload = refreshUmmahMonthPayload();

        foreach ($payload['data']['days'] as &$day) {
            $date = substr($day['prayer_datetimes']['maghrib'], 0, 10);
            $day['prayer_datetimes']['maghrib'] = "{$date}T{$maghribClock}:00+08:00";
        }

        return $payload;
    };
    Http::fake([
        'api.waktusolat.app/*' => Http::response([], 500),
        'ummahapi.com/*' => fn ($request) => Http::response(
            abs((float) $request['lat'] - 3.139) < 0.001 ? $monthFor('19:01') : $monthFor('19:05')
        ),
    ]);

    $action = app(BuildPrayerPreviewAction::class);
    $first = $action->handle('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 'WLY01', 3.00, 101.50);
    $second = $action->handle('MY', '2026-10-15', 'Asia/Kuala_Lumpur', 'WLY01', 3.20, 101.80);

    // Both serve the canonical zone month (19:01 + 5), never each
    // other's coordinate results.
    expect($first['starts']['selepas_maghrib'])->toBe('19:06')
        ->and($second['starts']['selepas_maghrib'])->toBe('19:06');

    $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'ummahapi.com'));

    expect($requests)->not->toBeEmpty();

    foreach ($requests as [$request]) {
        expect(abs((float) $request['lat'] - 3.139))->toBeLessThan(0.0001);
    }
});
