<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function ensureCountryForPlaceResolution(string $iso2, string $name): AddressCountry
{
    return ensureTestAddressCountry(
        iso2: $iso2,
        name: $name,
        iso3: $iso2 === 'ID' ? 'IDN' : 'MYS',
        timezones: [$iso2 === 'ID' ? 'Asia/Jakarta' : 'Asia/Kuala_Lumpur'],
        phoneCode: $iso2 === 'ID' ? '62' : '60',
    );
}

/**
 * @return array{package: State, area: AddressArea}
 */
function ensureMalaysiaStateForPlaceResolution(string $name = 'Selangor'): array
{
    $country = ensureCountryForPlaceResolution('MY', 'Malaysia');
    $packageState = State::query()->firstOrCreate(
        ['country_id' => $country->getKey(), 'name' => $name],
        ['code' => null, 'label' => $name],
    );
    $area = createTestAddressArea(
        $name,
        1,
        country: $country,
        type: 'state',
    );

    return ['package' => $packageState, 'area' => $area];
}

/**
 * @return array{package: State, area: AddressArea}
 */
function ensureStateForPlaceResolution(string $countryIso2, string $countryName, string $stateName, ?AddressCountry $country = null): array
{
    $country ??= ensureCountryForPlaceResolution($countryIso2, $countryName);
    $packageState = State::query()->firstOrCreate(
        ['country_id' => $country->getKey(), 'name' => $stateName],
        ['code' => null, 'label' => $stateName],
    );
    $area = createTestAddressArea(
        $stateName,
        1,
        country: $country,
        type: 'state',
    );

    return ['package' => $packageState, 'area' => $area];
}

it('maps a google place selection into local geography ids and address fields', function () {
    $state = ensureMalaysiaStateForPlaceResolution();
    $district = createTestAddressArea('Petaling', 2, parent: $state['area'], country: ensureCountryForPlaceResolution('MY', 'Malaysia'), type: 'district');
    $subdistrict = createTestAddressArea('Shah Alam', 3, parent: $district, country: ensureCountryForPlaceResolution('MY', 'Malaysia'), type: 'subdistrict');

    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake();

    $payload = app(ResolveGooglePlaceSelectionAction::class)->handle([
        'placeId' => 'place_abc123',
        'googleMapsURI' => 'https://www.google.com/maps/place/?q=place_id:place_abc123',
        'displayName' => ['text' => 'Masjid Sultan Salahuddin Abdul Aziz Shah'],
        'location' => [
            'lat' => 3.07853,
            'lng' => 101.52073,
        ],
        'addressComponents' => [
            ['longText' => 'Persiaran Masjid', 'shortText' => 'Persiaran Masjid', 'types' => ['route']],
            ['longText' => 'Seksyen 14', 'shortText' => 'Seksyen 14', 'types' => ['sublocality_level_1', 'sublocality', 'political']],
            ['longText' => '40000', 'shortText' => '40000', 'types' => ['postal_code']],
            ['longText' => 'Shah Alam', 'shortText' => 'Shah Alam', 'types' => ['locality', 'political']],
            ['longText' => 'Petaling', 'shortText' => 'Petaling', 'types' => ['administrative_area_level_2', 'political']],
            ['longText' => 'Selangor', 'shortText' => 'Selangor', 'types' => ['administrative_area_level_1', 'political']],
        ],
    ]);

    expect($payload['country_id'])->toBe((string) $state['package']->country_id)
        ->and($payload['state_id'])->toBe((string) $state['package']->id)
        ->and($payload['admin_area_1_id'])->toBe((string) $district->id)
        ->and($payload['admin_area_2_id'])->toBe((string) $subdistrict->id)
        ->and($payload['admin_area_3_id'])->toBeNull()
        ->and($payload['line1'])->toBe('Persiaran Masjid')
        ->and($payload['line2'])->toBe('Seksyen 14')
        ->and($payload['postcode'])->toBe('40000')
        ->and($payload['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=3.07853%2C101.52073&query_place_id=place_abc123')
        ->and($payload['provider_place_id'])->toBe('place_abc123')
        ->and($payload['google_display_name'])->toBe('Masjid Sultan Salahuddin Abdul Aziz Shah')
        ->and($payload['google_resolution_source'])->toBe('picker')
        ->and($payload['google_resolution_status'])->toBe('resolved')
        ->and(abs(((float) $payload['latitude']) - 3.07853))->toBeLessThan(0.000001)
        ->and(abs(((float) $payload['longitude']) - 101.52073))->toBeLessThan(0.000001);

    Http::assertNothingSent();
});

it('leaves ambiguous geography ids empty instead of guessing', function () {
    $state = ensureMalaysiaStateForPlaceResolution();

    createTestAddressArea('Petaling', 2, parent: $state['area'], country: ensureCountryForPlaceResolution('MY', 'Malaysia'), type: 'district');
    createTestAddressArea('Petaling', 2, parent: $state['area'], country: ensureCountryForPlaceResolution('MY', 'Malaysia'), type: 'district');

    $payload = app(ResolveGooglePlaceSelectionAction::class)->handle([
        'location' => [
            'lat' => 3.1,
            'lng' => 101.6,
        ],
        'addressComponents' => [
            ['longText' => 'Petaling', 'shortText' => 'Petaling', 'types' => ['administrative_area_level_2', 'political']],
            ['longText' => 'Selangor', 'shortText' => 'Selangor', 'types' => ['administrative_area_level_1', 'political']],
        ],
    ]);

    expect($payload['state_id'])->toBe((string) $state['package']->id)
        ->and($payload['admin_area_1_id'])->toBeNull()
        ->and($payload['admin_area_2_id'])->toBeNull()
        ->and($payload['admin_area_3_id'])->toBeNull();
});

it('resolves federal territory subdistricts directly from the state without a district', function () {
    $state = ensureMalaysiaStateForPlaceResolution('Kuala Lumpur');

    $subdistrict = createTestAddressArea('Setiawangsa', 3, parent: $state['area'], country: ensureCountryForPlaceResolution('MY', 'Malaysia'), type: 'subdistrict');

    $payload = app(ResolveGooglePlaceSelectionAction::class)->handle([
        'location' => [
            'lat' => 3.1732,
            'lng' => 101.7391,
        ],
        'addressComponents' => [
            ['longText' => 'Jalan Setiawangsa', 'shortText' => 'Jalan Setiawangsa', 'types' => ['route']],
            ['longText' => 'Taman Setiawangsa', 'shortText' => 'Taman Setiawangsa', 'types' => ['sublocality_level_1', 'sublocality', 'political']],
            ['longText' => '54200', 'shortText' => '54200', 'types' => ['postal_code']],
            ['longText' => 'Setiawangsa', 'shortText' => 'Setiawangsa', 'types' => ['locality', 'political']],
            ['longText' => 'Kuala Lumpur', 'shortText' => 'Kuala Lumpur', 'types' => ['administrative_area_level_1', 'political']],
        ],
    ]);

    expect($payload['state_id'])->toBe((string) $state['package']->id)
        ->and($payload['admin_area_1_id'])->toBeNull()
        ->and($payload['admin_area_2_id'])->toBe((string) $subdistrict->id)
        ->and($payload['admin_area_3_id'])->toBeNull()
        ->and($payload['line1'])->toBe('Jalan Setiawangsa')
        ->and($payload['line2'])->toBe('Taman Setiawangsa')
        ->and($payload['postcode'])->toBe('54200');
});

it('resolves non-malaysia geography using the picker country component', function () {
    $country = ensureCountryForPlaceResolution('ID', 'Indonesia');
    $state = ensureStateForPlaceResolution('ID', 'Indonesia', 'DKI Jakarta', $country);
    $district = createTestAddressArea('Jakarta Pusat', 2, parent: $state['area'], country: $country, type: 'district');
    $subdistrict = createTestAddressArea('Gambir', 3, parent: $district, country: $country, type: 'subdistrict');

    $payload = app(ResolveGooglePlaceSelectionAction::class)->handle([
        'location' => [
            'lat' => -6.1754,
            'lng' => 106.8272,
        ],
        'addressComponents' => [
            ['longText' => 'Jalan Medan Merdeka Selatan', 'shortText' => 'Jalan Medan Merdeka Selatan', 'types' => ['route']],
            ['longText' => 'Gambir', 'shortText' => 'Gambir', 'types' => ['locality', 'political']],
            ['longText' => '10110', 'shortText' => '10110', 'types' => ['postal_code']],
            ['longText' => 'Jakarta Pusat', 'shortText' => 'Jakarta Pusat', 'types' => ['administrative_area_level_2', 'political']],
            ['longText' => 'DKI Jakarta', 'shortText' => 'DKI Jakarta', 'types' => ['administrative_area_level_1', 'political']],
            ['longText' => 'Indonesia', 'shortText' => 'ID', 'types' => ['country', 'political']],
        ],
    ]);

    expect($payload['country_id'])->toBe((string) $country->id)
        ->and($payload['state_id'])->toBe((string) $state['package']->id)
        ->and($payload['admin_area_1_id'])->toBe((string) $district->id)
        ->and($payload['admin_area_2_id'])->toBe((string) $subdistrict->id)
        ->and($payload['admin_area_3_id'])->toBeNull()
        ->and($payload['postcode'])->toBe('10110');
});

it('uses the current country fallback when the picker payload omits the country component', function () {
    $country = ensureCountryForPlaceResolution('ID', 'Indonesia');
    $state = ensureStateForPlaceResolution('ID', 'Indonesia', 'DKI Jakarta', $country);
    $district = createTestAddressArea('Jakarta Pusat', 2, parent: $state['area'], country: $country, type: 'district');
    $subdistrict = createTestAddressArea('Gambir', 3, parent: $district, country: $country, type: 'subdistrict');

    $payload = app(ResolveGooglePlaceSelectionAction::class)->handle([
        'fallbackCountryId' => (string) $country->id,
        'location' => [
            'lat' => -6.1754,
            'lng' => 106.8272,
        ],
        'addressComponents' => [
            ['longText' => 'Jalan Medan Merdeka Selatan', 'shortText' => 'Jalan Medan Merdeka Selatan', 'types' => ['route']],
            ['longText' => 'Gambir', 'shortText' => 'Gambir', 'types' => ['locality', 'political']],
            ['longText' => '10110', 'shortText' => '10110', 'types' => ['postal_code']],
            ['longText' => 'Jakarta Pusat', 'shortText' => 'Jakarta Pusat', 'types' => ['administrative_area_level_2', 'political']],
            ['longText' => 'DKI Jakarta', 'shortText' => 'DKI Jakarta', 'types' => ['administrative_area_level_1', 'political']],
        ],
    ]);

    expect($payload['country_id'])->toBe((string) $country->id)
        ->and($payload['state_id'])->toBe((string) $state['package']->id)
        ->and($payload['admin_area_1_id'])->toBe((string) $district->id)
        ->and($payload['admin_area_2_id'])->toBe((string) $subdistrict->id)
        ->and($payload['admin_area_3_id'])->toBeNull();
});
