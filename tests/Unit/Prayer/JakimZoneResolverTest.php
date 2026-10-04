<?php

use App\Jobs\ResolveGpsZone;
use App\Services\Prayer\JakimZoneResolver;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

uses(TestCase::class);

it('ships a 60-zone snapshot with state defaults pointing at known zones', function () {
    $resolver = app(JakimZoneResolver::class);

    expect($resolver->zones())->toHaveCount(60)
        ->and($resolver->isKnownZone('WLY01'))->toBeTrue()
        ->and($resolver->isKnownZone('WLY02'))->toBeTrue()
        ->and($resolver->isKnownZone('NOPE1'))->toBeFalse();

    foreach (config('prayer_zones.state_defaults', []) as $code => $default) {
        expect($resolver->isKnownZone($default['zone']))->toBeTrue("state default {$code} zone {$default['zone']} is known");
    }

    expect(config('prayer_zones.state_defaults', []))->toHaveCount(16);
});

it('resolves memoized coordinates without HTTP', function () {
    $resolver = app(JakimZoneResolver::class);
    $resolver->rememberZone(3.1390, 101.6869, 'WLY01');

    $resolved = $resolver->resolve(3.1390, 101.6869, '10');

    expect($resolved)->toBe([
        'zone' => 'WLY01',
        'lat' => 3.1390,
        'lng' => 101.6869,
        'source' => 'gps-memo',
    ]);
});

it('falls back to the state default zone while keeping input coordinates', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(1.4927, 103.7414, '01');

    expect($resolved['zone'])->toBe('JHR02')
        ->and($resolved['lat'])->toBe(1.4927)
        ->and($resolved['lng'])->toBe(103.7414)
        ->and($resolved['source'])->toBe('state-default:01');
});

it('falls back to capital coordinates when only a state is known', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(null, null, '10');

    expect($resolved)->toBe([
        'zone' => 'SGR01',
        'lat' => 3.0733,
        'lng' => 101.5185,
        'source' => 'state-default:10',
    ]);
});

it('falls back to the tagged country default when nothing is known', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(null, null, null);

    expect($resolved)->toBe([
        'zone' => 'WLY01',
        'lat' => 3.1390,
        'lng' => 101.6869,
        'source' => 'country-default',
    ]);
});

it('maps zones to state-capital coordinates for coordinate providers', function () {
    $resolver = app(JakimZoneResolver::class);

    expect($resolver->coordsForZone('WLY01'))->toBe(['lat' => 3.1390, 'lng' => 101.6869])
        ->and($resolver->coordsForZone('SGR01'))->toBe(['lat' => 3.0733, 'lng' => 101.5185])
        ->and($resolver->coordsForZone('SBH07'))->toBe(['lat' => 5.9804, 'lng' => 116.0735]);
});

it('maps Labuan to Labuan coordinates instead of Kuala Lumpur', function () {
    expect(app(JakimZoneResolver::class)->coordsForZone('WLY02'))->toBe(['lat' => 5.28, 'lng' => 115.24]);
});

it('prefers per-zone towns over state capitals for far-flung zones', function () {
    $resolver = app(JakimZoneResolver::class);

    expect($resolver->coordsForZone('SWK01'))->toBe(['lat' => 4.75, 'lng' => 115.01])
        ->and($resolver->coordsForZone('SBH01'))->toBe(['lat' => 5.84, 'lng' => 118.12])
        ->and($resolver->coordsForZone('PHG06'))->toBe(['lat' => 4.47, 'lng' => 101.38]);
});

it('returns no coordinates for unknown zones', function () {
    expect(app(JakimZoneResolver::class)->coordsForZone('NOPE1'))->toBeNull();
});

it('keeps the gps memo ahead of district candidates', function () {
    $resolver = app(JakimZoneResolver::class);
    $resolver->rememberZone(2.0535, 102.5717, 'JHR04');

    $resolved = $resolver->resolve(2.0535, 102.5717, '01', ['Johor Bahru']);

    expect($resolved['zone'])->toBe('JHR04')
        ->and($resolved['source'])->toBe('gps-memo');
});

it('matches a non-capital district ahead of the state default', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(null, null, '01', ['Muar']);

    expect($resolved['zone'])->toBe('JHR04')
        ->and($resolved['source'])->toBe('district-match')
        ->and($resolved['lat'])->toBe((float) config('prayer_zones.zone_coords.JHR04.lat'))
        ->and($resolved['lng'])->toBe((float) config('prayer_zones.zone_coords.JHR04.lng'));
});

it('keeps input coordinates on a district match', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(2.0442, 102.5656, '01', ['Muar']);

    expect($resolved['zone'])->toBe('JHR04')
        ->and($resolved['lat'])->toBe(2.0442)
        ->and($resolved['lng'])->toBe(102.5656)
        ->and($resolved['source'])->toBe('district-match');
});

it('tries district candidates in order', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(null, null, '01', ['Atlantis', 'Muar']);

    expect($resolved['zone'])->toBe('JHR04')
        ->and($resolved['source'])->toBe('district-match');
});

it('falls through to the state default when no candidate matches', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(null, null, '01', ['Atlantis']);

    expect($resolved['zone'])->toBe('JHR02')
        ->and($resolved['source'])->toBe('state-default:01');
});

it('resolves Mukim Chiku ahead of its Gua Musang parent', function () {
    $resolved = app(JakimZoneResolver::class)->resolve(null, null, '03', ['Mukim Chiku', 'Gua Musang']);

    expect($resolved['zone'])->toBe('KTN01')
        ->and($resolved['source'])->toBe('district-match');
});

it('drops memoized zones removed from the snapshot so GPS remaps', function () {
    Bus::fake();

    $resolver = app(JakimZoneResolver::class);
    $resolver->rememberZone(3.1390, 101.6869, 'WLY01');

    expect($resolver->memoizedZone(3.1390, 101.6869))->toBe('WLY01');

    // The snapshot drops the zone.
    $zones = config('prayer_zones.zones');
    unset($zones['WLY01']);
    config(['prayer_zones.zones' => $zones]);

    // The obsolete memo reads as a miss and is forgotten...
    expect($resolver->memoizedZone(3.1390, 101.6869))->toBeNull();

    // ...resolution falls back instead of serving the dead zone...
    expect($resolver->resolve(3.1390, 101.6869, '14', [])['source'])->toBe('state-default:14');

    // ...GPS dispatch proceeds...
    $resolver->queueGpsResolution(3.1390, 101.6869);
    Bus::assertDispatched(ResolveGpsZone::class);

    // ...and a replacement mapping sticks.
    $resolver->rememberZone(3.1390, 101.6869, 'SGR01');

    expect($resolver->memoizedZone(3.1390, 101.6869))->toBe('SGR01');
});
