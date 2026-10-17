<?php

use App\Support\Location\GooglePlaceIdentity;

it('preserves google CID precision through the full unsigned 64-bit range', function () {
    expect(GooglePlaceIdentity::cidFromFeatureId('0x808f778c55555555:0xffffffffffffffff'))
        ->toBe('18446744073709551615')
        ->and(GooglePlaceIdentity::hexCidFromDecimal('18446744073709551615'))
        ->toBe('ffffffffffffffff')
        ->and(GooglePlaceIdentity::canonicalCid('018446744073709551615'))
        ->toBe('18446744073709551615');
});

it('normalizes decimal and feature-derived CIDs from Google Maps URLs', function () {
    expect(GooglePlaceIdentity::cidsFromGoogleMapsUrl('https://maps.google.com/?cid=001188566389576577631'))
        ->toBe(['1188566389576577631'])
        ->and(GooglePlaceIdentity::cidsFromGoogleMapsUrl('https://www.google.com/maps/place/?q=place_id:x!1s0x808f778c55555555:0xa4f25c571acded3f'))
        ->toBe(['11885663895765773631']);
});

it('keeps stable photo attribution metadata while omitting expiring photo resource names', function () {
    $identity = GooglePlaceIdentity::fromPlace([
        'id' => 'ChIJ_photo_place',
        'photos' => [[
            'name' => 'places/ChIJ_photo_place/photos/temporary-reference',
            'widthPx' => 1600,
            'heightPx' => 1200,
            'photoUri' => 'https://lh3.googleusercontent.com/temporary-photo',
            'authorAttributions' => [[
                'displayName' => 'Photo contributor',
                'uri' => 'https://maps.google.com/maps/contrib/123',
                'photoUri' => 'https://lh3.googleusercontent.com/contributor',
            ]],
            'googleMapsUri' => 'https://maps.google.com/maps/place/?q=photo',
        ]],
    ]);

    expect(data_get($identity, 'metadata.google_places.place.photos.0.name'))->toBeNull()
        ->and(data_get($identity, 'metadata.google_places.place.photos.0.photoUri'))->toBeNull()
        ->and(data_get($identity, 'metadata.google_places.place.photos.0.widthPx'))->toBe(1600)
        ->and(data_get($identity, 'metadata.google_places.place.photos.0.authorAttributions.0.displayName'))->toBe('Photo contributor')
        ->and(data_get($identity, 'metadata.google_places.place.photos.0.authorAttributions.0.photoUri'))->toBeNull()
        ->and(data_get($identity, 'metadata.google_places.place.photos.0.googleMapsUri'))->toBe('https://maps.google.com/maps/place/?q=photo');
});
