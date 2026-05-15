<?php

use App\Actions\Location\ResolveGooglePlaceSelectionAction;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function ensureCountryForPlaceResolution(string $iso2, string $name, ?int $id = null): int
{
    $countryId = DB::table('countries')
        ->when($id !== null, fn ($query) => $query->where('id', $id), fn ($query) => $query->where('iso2', $iso2))
        ->value('id');

    if (is_int($countryId)) {
        return $countryId;
    }

    return DB::table('countries')->insertGetId(array_filter([
        'id' => $id,
        'iso2' => $iso2,
        'name' => $name,
        'status' => 1,
        'phone_code' => $iso2 === 'ID' ? '62' : '60',
        'iso3' => $iso2 === 'ID' ? 'IDN' : 'MYS',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ], static fn (mixed $value): bool => $value !== null));
}

function ensureMalaysiaStateForPlaceResolution(string $name = 'Selangor'): State
{
    $countryId = ensureCountryForPlaceResolution('MY', 'Malaysia', 132);

    $stateId = DB::table('states')
        ->where('country_id', $countryId)
        ->where('name', $name)
        ->value('id');

    if (! is_int($stateId)) {
        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => $name,
            'country_code' => 'MY',
        ]);
    }

    return State::query()->findOrFail($stateId);
}

function ensureStateForPlaceResolution(string $countryIso2, string $countryName, string $stateName, ?int $countryId = null): State
{
    $countryId ??= ensureCountryForPlaceResolution($countryIso2, $countryName);

    $stateId = DB::table('states')
        ->where('country_id', $countryId)
        ->where('name', $stateName)
        ->value('id');

    if (! is_int($stateId)) {
        $stateId = DB::table('states')->insertGetId([
            'country_id' => $countryId,
            'name' => $stateName,
            'country_code' => $countryIso2,
        ]);
    }

    return State::query()->findOrFail($stateId);
}

it('maps a google place selection into local geography ids and address fields', function () {
    $state = ensureMalaysiaStateForPlaceResolution();
    $district = District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);
    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'MY',
        'name' => 'Shah Alam',
    ]);

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

    expect($payload['country_id'])->toBe(132)
        ->and($payload['state_id'])->toBe((int) $state->id)
        ->and($payload['district_id'])->toBe((int) $district->id)
        ->and($payload['subdistrict_id'])->toBe((int) $subdistrict->id)
        ->and($payload['line1'])->toBe('Persiaran Masjid')
        ->and($payload['line2'])->toBe('Seksyen 14')
        ->and($payload['postcode'])->toBe('40000')
        ->and($payload['google_maps_url'])->toBe('https://www.google.com/maps/search/?api=1&query=3.07853%2C101.52073&query_place_id=place_abc123')
        ->and($payload['google_place_id'])->toBe('place_abc123')
        ->and($payload['google_display_name'])->toBe('Masjid Sultan Salahuddin Abdul Aziz Shah')
        ->and($payload['google_resolution_source'])->toBe('picker')
        ->and($payload['google_resolution_status'])->toBe('resolved')
        ->and(abs(((float) $payload['lat']) - 3.07853))->toBeLessThan(0.000001)
        ->and(abs(((float) $payload['lng']) - 101.52073))->toBeLessThan(0.000001);

    Http::assertNothingSent();
});

it('leaves ambiguous geography ids empty instead of guessing', function () {
    $state = ensureMalaysiaStateForPlaceResolution();

    District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);

    District::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'country_code' => 'MY',
        'name' => 'Petaling',
    ]);

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

    expect($payload['state_id'])->toBe((int) $state->id)
        ->and($payload['district_id'])->toBeNull()
        ->and($payload['subdistrict_id'])->toBeNull();
});

it('resolves federal territory subdistricts directly from the state without a district', function () {
    $state = ensureMalaysiaStateForPlaceResolution('Kuala Lumpur');

    $subdistrict = Subdistrict::query()->create([
        'country_id' => (int) $state->country_id,
        'state_id' => (int) $state->id,
        'district_id' => null,
        'country_code' => 'MY',
        'name' => 'Setiawangsa',
    ]);

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

    expect($payload['state_id'])->toBe((int) $state->id)
        ->and($payload['district_id'])->toBeNull()
        ->and($payload['subdistrict_id'])->toBe((int) $subdistrict->id)
        ->and($payload['line1'])->toBe('Jalan Setiawangsa')
        ->and($payload['line2'])->toBe('Taman Setiawangsa')
        ->and($payload['postcode'])->toBe('54200');
});

it('resolves non-malaysia geography using the picker country component', function () {
    $countryId = ensureCountryForPlaceResolution('ID', 'Indonesia');
    $state = ensureStateForPlaceResolution('ID', 'Indonesia', 'DKI Jakarta', $countryId);
    $district = District::query()->create([
        'country_id' => $countryId,
        'state_id' => (int) $state->id,
        'country_code' => 'ID',
        'name' => 'Jakarta Pusat',
    ]);
    $subdistrict = Subdistrict::query()->create([
        'country_id' => $countryId,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'ID',
        'name' => 'Gambir',
    ]);

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

    expect($payload['country_id'])->toBe($countryId)
        ->and($payload['state_id'])->toBe((int) $state->id)
        ->and($payload['district_id'])->toBe((int) $district->id)
        ->and($payload['subdistrict_id'])->toBe((int) $subdistrict->id)
        ->and($payload['postcode'])->toBe('10110');
});

it('uses the current country fallback when the picker payload omits the country component', function () {
    $countryId = ensureCountryForPlaceResolution('ID', 'Indonesia');
    $state = ensureStateForPlaceResolution('ID', 'Indonesia', 'DKI Jakarta', $countryId);
    $district = District::query()->create([
        'country_id' => $countryId,
        'state_id' => (int) $state->id,
        'country_code' => 'ID',
        'name' => 'Jakarta Pusat',
    ]);
    $subdistrict = Subdistrict::query()->create([
        'country_id' => $countryId,
        'state_id' => (int) $state->id,
        'district_id' => (int) $district->id,
        'country_code' => 'ID',
        'name' => 'Gambir',
    ]);

    $payload = app(ResolveGooglePlaceSelectionAction::class)->handle([
        'fallbackCountryId' => (string) $countryId,
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

    expect($payload['country_id'])->toBe($countryId)
        ->and($payload['state_id'])->toBe((int) $state->id)
        ->and($payload['district_id'])->toBe((int) $district->id)
        ->and($payload['subdistrict_id'])->toBe((int) $subdistrict->id);
});
