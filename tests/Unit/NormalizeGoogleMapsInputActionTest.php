<?php

use App\Actions\Location\NormalizeGoogleMapsInputAction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('canonicalizes picker-provided place metadata without extra server lookups', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake();

    $normalized = app(NormalizeGoogleMapsInputAction::class)->handle([
        'google_maps_url' => 'https://maps.google.com/?cid=13041553140845823475',
        'google_place_id' => 'place_123',
        'google_display_name' => 'Masjid Sultan Salahuddin Abdul Aziz Shah',
        'lat' => 3.07853,
        'lng' => 101.520698,
        'google_resolution_source' => 'picker',
    ]);

    expect($normalized['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=3.07853%2C101.520698&query_place_id=place_123')
        ->and($normalized['google_place_id'])->toBe('place_123')
        ->and($normalized['google_resolution_source'])->toBe('picker')
        ->and($normalized['google_resolution_status'])->toBe('resolved')
        ->and($normalized['google_resolution_message'])->toBeNull();

    Http::assertNothingSent();
});

it('parses direct place-id links without calling google apis again', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake();

    $normalized = app(NormalizeGoogleMapsInputAction::class)->handle([
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=Masjid+Sultan+Salahuddin+Abdul+Aziz+Shah&query_place_id=place_123',
    ]);

    expect($normalized['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=Masjid+Sultan+Salahuddin+Abdul+Aziz+Shah&query_place_id=place_123')
        ->and($normalized['google_place_id'])->toBe('place_123')
        ->and($normalized['google_resolution_status'])->toBe('resolved');

    Http::assertNothingSent();
});

it('resolves short links and recovers a place id conservatively', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake([
        'https://maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Masjid+Jamik+Ungku+Ahmad,+Kampung+Separap/@1.9089362,102.865462,925m/data=!3m2!1e3!4b1!4m6!3m5!1s0x31d0539173ae7dd9:0xb4fce77c077ec5f3!8m2!3d1.9089362!4d102.865462!16s%2Fg%2F11sqw6yjrc?hl=en-US&entry=ttu',
        ]),
        'https://places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [[
                'id' => 'ChIJ2X2uc5FT0DER88V-B3zn_LQ',
                'displayName' => ['text' => 'Masjid Jamik Ungku Ahmad, Kampung Separap'],
                'location' => [
                    'latitude' => 1.9089362,
                    'longitude' => 102.865462,
                ],
            ]],
        ], 200),
    ]);

    $normalized = app(NormalizeGoogleMapsInputAction::class)->handle([
        'google_maps_url' => 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8',
    ]);

    expect($normalized['google_place_id'])->toBe('ChIJ2X2uc5FT0DER88V-B3zn_LQ')
        ->and($normalized['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=1.9089362%2C102.865462&query_place_id=ChIJ2X2uc5FT0DER88V-B3zn_LQ')
        ->and($normalized['google_resolution_status'])->toBe('resolved');

    Http::assertSentCount(2);
});

it('keeps local normalization working while skipping places api lookups when remote lookup is disabled', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake([
        'https://maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Masjid+Jamik+Ungku+Ahmad,+Kampung+Separap/@1.9089362,102.865462,925m/data=!3m2!1e3!4b1!4m6!3m5!1s0x31d0539173ae7dd9:0xb4fce77c077ec5f3!8m2!3d1.9089362!4d102.865462!16s%2Fg%2F11sqw6yjrc?hl=en-US&entry=ttu',
        ]),
        'https://places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [[
                'id' => 'ChIJ2X2uc5FT0DER88V-B3zn_LQ',
                'displayName' => ['text' => 'Masjid Jamik Ungku Ahmad, Kampung Separap'],
                'location' => [
                    'latitude' => 1.9089362,
                    'longitude' => 102.865462,
                ],
            ]],
        ], 200),
    ]);

    $normalized = app(NormalizeGoogleMapsInputAction::class)->handle([
        'google_maps_url' => 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8',
        'google_maps_remote_lookup_enabled' => false,
    ]);

    expect($normalized['google_place_id'])->toBeNull()
        ->and($normalized['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=1.9089362%2C102.865462')
        ->and($normalized['lat'])->toBe(1.9089362)
        ->and($normalized['lng'])->toBe(102.865462)
        ->and($normalized['google_resolution_status'])->toBe('partial');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with((string) $request->url(), 'https://maps.app.goo.gl/'));
});

it('resolves cid links through the redirect and search path', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake([
        'https://maps.google.com/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Masjid+Jamik+Ungku+Ahmad,+Kampung+Separap/@1.9089362,102.865462,925m/data=!3m2!1e3!4b1!4m6!3m5!1s0x31d0539173ae7dd9:0xb4fce77c077ec5f3!8m2!3d1.9089362!4d102.865462!16s%2Fg%2F11sqw6yjrc?hl=en-US&entry=ttu',
        ]),
        'https://places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [[
                'id' => 'ChIJ2X2uc5FT0DER88V-B3zn_LQ',
                'displayName' => ['text' => 'Masjid Jamik Ungku Ahmad, Kampung Separap'],
                'location' => [
                    'latitude' => 1.9089362,
                    'longitude' => 102.865462,
                ],
            ]],
        ], 200),
    ]);

    $normalized = app(NormalizeGoogleMapsInputAction::class)->handle([
        'google_maps_url' => 'https://maps.google.com/?cid=13041553140845823475',
    ]);

    expect($normalized['google_place_id'])->toBe('ChIJ2X2uc5FT0DER88V-B3zn_LQ')
        ->and($normalized['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=1.9089362%2C102.865462&query_place_id=ChIJ2X2uc5FT0DER88V-B3zn_LQ');

    Http::assertSentCount(2);
});

it('canonicalizes coordinate-only urls without guessing a place id', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake();

    $normalized = app(NormalizeGoogleMapsInputAction::class)->handle([
        'google_maps_url' => 'https://www.google.com/maps/@3.139003,101.686855,17z',
    ]);

    expect($normalized['google_place_id'])->toBeNull()
        ->and($normalized['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=3.139003%2C101.686855')
        ->and($normalized['google_resolution_status'])->toBe('partial');

    Http::assertNothingSent();
});

it('does not retry unchanged unresolved manual links after the first failed recovery', function () {
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake([
        'https://maps.app.goo.gl/*' => Http::response('', 302, [
            'Location' => 'https://www.google.com/maps/place/Masjid+Jamik+Ungku+Ahmad,+Kampung+Separap/@1.9089362,102.865462,925m/data=!3m2!1e3!4b1!4m6!3m5!1s0x31d0539173ae7dd9:0xb4fce77c077ec5f3!8m2!3d1.9089362!4d102.865462!16s%2Fg%2F11sqw6yjrc?hl=en-US&entry=ttu',
        ]),
        'https://places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [
                [
                    'id' => 'place_one',
                    'displayName' => ['text' => 'Masjid Jamik Ungku Ahmad, Kampung Separap'],
                    'location' => [
                        'latitude' => 1.9089362,
                        'longitude' => 102.865462,
                    ],
                ],
                [
                    'id' => 'place_two',
                    'displayName' => ['text' => 'Masjid Jamik Ungku Ahmad, Kampung Separap'],
                    'location' => [
                        'latitude' => 1.909,
                        'longitude' => 102.8657,
                    ],
                ],
            ],
        ], 200),
    ]);

    $action = app(NormalizeGoogleMapsInputAction::class);

    $firstPass = $action->handle([
        'google_maps_url' => 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8',
    ]);

    expect($firstPass['google_place_id'])->toBeNull()
        ->and($firstPass['google_resolution_status'])->toBe('partial');

    $secondPass = $action->handle($firstPass);

    expect($secondPass['google_place_id'])->toBeNull()
        ->and($secondPass['google_resolution_status'])->toBe('partial');

    Http::assertSentCount(2);
});
