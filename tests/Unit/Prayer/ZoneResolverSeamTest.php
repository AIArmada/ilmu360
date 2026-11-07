<?php

use App\Features\Prayer\Actions\ResolvePrayerAnchorAction;
use App\Features\Prayer\Actions\ResolvePrayerStartClockAction;
use App\Features\Prayer\Api\PrayerQuery;
use App\Features\Prayer\EntryPoints\Jobs\RefreshPrayerTimes;
use App\Features\Prayer\EntryPoints\Jobs\ResolveGpsZone;
use App\Features\Prayer\Services\JakimZoneResolver;
use App\Features\Prayer\Services\NullZoneResolver;
use App\Features\Prayer\Services\PrayerProviderRegistry;
use App\Shared\Prayer\EventPrayerTime;
use App\Shared\Prayer\PrayerReference;
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
