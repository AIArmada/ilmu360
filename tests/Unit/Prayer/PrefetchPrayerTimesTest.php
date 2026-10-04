<?php

use App\Jobs\RefreshPrayerTimes;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\PrayerTimesCache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

it('dispatches refreshes for cold months and skips warm ones', function () {
    Queue::fake();

    $this->artisan('app:prayer:prefetch', ['--zone' => 'WLY01', '--month' => '2026-10'])
        ->assertSuccessful();

    Queue::assertPushed(RefreshPrayerTimes::class, 1);

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', prayerCompleteMonth('2026-10'), 'MY');

    $this->artisan('app:prayer:prefetch', ['--zone' => 'WLY01', '--month' => '2026-10'])
        ->assertSuccessful();

    Queue::assertPushed(RefreshPrayerTimes::class, 1);
});

it('rejects unknown zones and malformed months', function () {
    $this->artisan('app:prayer:prefetch', ['--zone' => 'NOPE1'])
        ->assertFailed();

    $this->artisan('app:prayer:prefetch', ['--month' => 'Oct 2026'])
        ->assertFailed();
});

it('reports without dispatching on dry runs', function () {
    Queue::fake();

    $this->artisan('app:prayer:prefetch', ['--zone' => 'WLY01', '--month' => '2026-10', '--dry-run' => true])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('dispatches scoped refreshes for the requested country', function () {
    Queue::fake();

    $this->artisan('app:prayer:prefetch', ['--country' => 'MY', '--zone' => 'WLY01', '--month' => '2026-10'])
        ->assertSuccessful();

    Queue::assertPushed(
        RefreshPrayerTimes::class,
        fn (RefreshPrayerTimes $job): bool => $job->kind === 'zone-month'
            && ($job->scope['country'] ?? null) === 'MY'
            && ($job->scope['zone'] ?? null) === 'WLY01',
    );
});

it('dispatches nothing for coordinate-only countries', function () {
    Queue::fake();

    $this->artisan('app:prayer:prefetch', ['--country' => 'XX', '--month' => '2026-10'])
        ->assertSuccessful();

    Queue::assertNothingPushed();

    $this->artisan('app:prayer:prefetch', ['--country' => 'XX', '--zone' => 'XX01'])
        ->assertFailed();
});

it('dispatches zone-month refresh without a requester timezone for a second zoned country', function () {
    Queue::fake();
    config([
        'prayer.zone_resolvers.YY' => JakimZoneResolver::class,
        'prayer.country_timezones.YY' => 'Asia/Jakarta',
    ]);

    $this->artisan('app:prayer:prefetch', ['--country' => 'YY', '--zone' => 'WLY01', '--month' => '2026-10'])
        ->assertSuccessful();

    // The job derives Asia/Jakarta from country configuration itself.
    Queue::assertPushed(
        RefreshPrayerTimes::class,
        fn (RefreshPrayerTimes $job): bool => $job->kind === 'zone-month'
            && ($job->scope['country'] ?? null) === 'YY'
            && ! array_key_exists('timezone', $job->scope),
    );
});

it('re-dispatches a fallback-filled month so the primary can heal it', function () {
    Queue::fake();

    app(PrayerTimesCache::class)->putMonthly('WLY01', '2026-10', array_map(
        fn ($dto) => prayerWithCalcFingerprint($dto),
        prayerCompleteMonth('2026-10', 'ummah:malaysia/Shafi')
    ), 'MY');

    $this->artisan('app:prayer:prefetch', ['--zone' => 'WLY01', '--month' => '2026-10'])
        ->assertSuccessful();

    // Complete but fallback-sourced: still dispatched for healing (the
    // refresh job's self-heal then restores primary coverage).
    Queue::assertPushed(RefreshPrayerTimes::class, 1);
});
