<?php

use App\Actions\Location\FetchGooglePlacePhotoPreviewsAction;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

function configureGooglePlacePhotoPreviewActionTest(): void
{
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'test-server-key');
}

it('returns only transient preview data and preserves required photo attribution', function () {
    configureGooglePlacePhotoPreviewActionTest();
    Http::preventStrayRequests();
    Http::fake([
        'https://places.googleapis.com/v1/places/photo-place/photos/photo-one/media*' => Http::response([
            'name' => 'places/photo-place/photos/photo-one/media',
            'photoUri' => 'https://lh3.googleusercontent.com/photo-one=s800',
        ], 200),
    ]);

    $previews = FetchGooglePlacePhotoPreviewsAction::run('photo-place', [[
        'name' => 'places/photo-place/photos/photo-one',
        'widthPx' => 1600,
        'heightPx' => 1200,
        'authorAttributions' => [[
            'displayName' => 'A Contributor',
            'uri' => '//maps.google.com/maps/contrib/123',
            'photoUri' => '//lh3.googleusercontent.com/contributor-photo',
        ]],
        'googleMapsUri' => 'https://maps.google.com/?cid=123',
    ]]);

    expect($previews)->toBe([
        [
            'preview_url' => 'https://lh3.googleusercontent.com/photo-one=s800',
            'width' => 1600,
            'height' => 1200,
            'attributions' => [[
                'display_name' => 'A Contributor',
                'uri' => 'https://maps.google.com/maps/contrib/123',
            ]],
            'google_maps_uri' => 'https://maps.google.com/?cid=123',
        ],
    ]);

    Http::assertSent(function (ClientRequest $request): bool {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://places.googleapis.com/v1/places/photo-place/photos/photo-one/media?')
            && ($query['skipHttpRedirect'] ?? null) === 'true'
            && ($query['maxWidthPx'] ?? null) === '800'
            && $request->hasHeader('X-Goog-Api-Key', 'test-server-key');
    });
    Http::assertSentCount(1);
});

it('limits photo lookups to three per current place', function () {
    configureGooglePlacePhotoPreviewActionTest();
    Http::preventStrayRequests();
    Http::fake([
        'https://places.googleapis.com/v1/places/bounded-place/photos/*/media*' => Http::response([
            'photoUri' => 'https://lh3.googleusercontent.com/preview',
        ], 200),
    ]);

    $photos = array_map(
        fn (int $photoNumber): array => [
            'name' => "places/bounded-place/photos/photo-{$photoNumber}",
            'widthPx' => 600,
            'heightPx' => 400,
            'googleMapsUri' => 'https://maps.google.com/?cid=123',
            'authorAttributions' => [['displayName' => 'Photo contributor']],
        ],
        range(1, 9),
    );

    $previews = FetchGooglePlacePhotoPreviewsAction::run('bounded-place', $photos);

    expect($previews)->toHaveCount(3);
    Http::assertSentCount(3);
});

it('omits previews when the optional photo endpoint fails or cannot connect', function () {
    configureGooglePlacePhotoPreviewActionTest();
    Http::preventStrayRequests();
    Http::fake([
        'https://places.googleapis.com/v1/places/failed-place/photos/photo-500/media*' => Http::response([], 503),
        'https://places.googleapis.com/v1/places/failed-place/photos/photo-connection/media*' => Http::failedConnection(),
    ]);

    $previews = FetchGooglePlacePhotoPreviewsAction::run('failed-place', [
        ['name' => 'places/failed-place/photos/photo-500'],
        ['name' => 'places/failed-place/photos/photo-connection'],
    ]);

    expect($previews)->toBe([]);
    Http::assertSentCount(2);
});

it('ignores foreign photo names and rejects preview urls outside googleusercontent', function () {
    configureGooglePlacePhotoPreviewActionTest();
    Http::preventStrayRequests();
    Http::fake([
        'https://places.googleapis.com/v1/places/current-place/photos/photo-one/media*' => Http::response([
            'photoUri' => 'https://images.example.test/photo-one.jpg',
        ], 200),
    ]);

    $previews = FetchGooglePlacePhotoPreviewsAction::run('current-place', [
        ['name' => 'places/another-place/photos/foreign-photo'],
        ['name' => 'places/current-place/photos/photo-one'],
    ]);

    expect($previews)->toBe([]);
    Http::assertSentCount(1);
});
