<?php

use App\Actions\Prayer\ResolvePrayerAnchorAction;
use App\Actions\Prayer\ResolvePrayerStartClockAction;
use App\Data\Prayer\PrayerQuery;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerReference;
use App\Jobs\RefreshPrayerTimes;
use App\Jobs\ResolveGpsZone;
use App\Services\Prayer\JakimZoneResolver;
use App\Services\Prayer\NullZoneResolver;
use App\Services\Prayer\PrayerProviderRegistry;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class);

it('resolves zone adapters per country from configuration', function () {
    $registry = app(PrayerProviderRegistry::class);

    expect($registry->zoneResolverFor('MY'))->toBeInstanceOf(JakimZoneResolver::class)
        ->and($registry->zoneResolverFor('my'))->toBeInstanceOf(JakimZoneResolver::class)
        ->and($registry->zoneResolverFor('XX'))->toBeInstanceOf(NullZoneResolver::class);
});

it('falls back to the null adapter for unresolvable mappings', function () {
    config()->set('prayer.zone_resolvers', ['MY' => 'Does\\Not\\Exist']);

    expect(app(PrayerProviderRegistry::class)->zoneResolverFor('MY'))->toBeInstanceOf(NullZoneResolver::class);

    config()->set('prayer.zone_resolvers', ['MY' => stdClass::class]);

    expect(app(PrayerProviderRegistry::class)->zoneResolverFor('MY'))->toBeInstanceOf(NullZoneResolver::class);

    config()->set('prayer.zone_resolver_default', 'Does\\Not\\Exist Either');

    expect(app(PrayerProviderRegistry::class)->zoneResolverFor('XX'))->toBeInstanceOf(NullZoneResolver::class);
});

it('passes coordinates through untouched for coordinate-only countries', function () {
    $resolver = new NullZoneResolver;

    expect($resolver->resolve(1.3521, 103.8198, 'SG'))->toBe([
        'zone' => null,
        'lat' => 1.3521,
        'lng' => 103.8198,
        'source' => 'no-zone',
    ])->and($resolver->resolve(null, null, null))->toBe([
        'zone' => null,
        'lat' => null,
        'lng' => null,
        'source' => 'no-zone',
    ])->and($resolver->isKnownZone('WLY01'))->toBeFalse()
        ->and($resolver->coordsForZone('WLY01'))->toBeNull();
});

it('never queues gps lookups for coordinate-only countries', function () {
    Bus::fake();

    (new NullZoneResolver)->queueGpsResolution(1.3521, 103.8198);

    Bus::assertNotDispatched(ResolveGpsZone::class);
});

it('queues gps lookups on memo misses only', function () {
    Bus::fake();

    $resolver = app(JakimZoneResolver::class);
    $resolver->queueGpsResolution(2.0442, 102.5656);

    Bus::assertDispatchedAfterResponse(ResolveGpsZone::class);

    Bus::fake();

    $resolver->rememberZone(2.0442, 102.5656, 'JHR04');
    $resolver->queueGpsResolution(2.0442, 102.5656);

    Bus::assertNotDispatched(ResolveGpsZone::class);
});

it('resolves coordinate-only countries without zones or gps jobs', function () {
    config()->set('prayer.enabled', true);
    Bus::fake();

    $result = app(ResolvePrayerStartClockAction::class)->handle(
        'XX', '2026-10-15', 'UTC', EventPrayerTime::SelepasMaghrib, 1.3521, 103.8198,
    );

    expect($result)->toBeNull();

    Bus::assertNotDispatched(ResolveGpsZone::class);
});

it('routes zoned queries by zone presence rather than country', function () {
    Bus::fake();

    $result = app(ResolvePrayerAnchorAction::class)->handle(
        new PrayerQuery('XX', '2026-10-15', 'UTC', 1.3521, 103.8198, 'XX01'),
        PrayerReference::Maghrib,
    );

    expect($result)->toBeNull();

    // No requester timezone in scope: the job derives the canonical
    // country timezone so deferred warming matches preview/prefetch.
    Bus::assertDispatchedAfterResponse(
        RefreshPrayerTimes::class,
        fn (RefreshPrayerTimes $job): bool => $job->kind === 'zone-month'
            && ($job->scope['zone'] ?? null) === 'XX01'
            && ($job->scope['country'] ?? null) === 'XX'
            && ! array_key_exists('timezone', $job->scope),
    );
});
