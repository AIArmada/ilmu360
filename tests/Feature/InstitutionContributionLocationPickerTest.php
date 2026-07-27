<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\State;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ensureCountryForLocationPicker(string $iso2, string $name): AddressCountry
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
function ensureMalaysiaStateForLocationPicker(string $name = 'Selangor'): array
{
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    $packageState = State::query()->firstOrCreate(
        ['country_id' => $country->getKey(), 'name' => $name],
        ['code' => null],
    );
    $area = createTestAddressArea(
        $name,
        1,
        country: $country,
        type: 'state',
    );

    return ['package' => $packageState, 'area' => $area];
}

it('renders the institution location picker when google places is enabled', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertDontSee(__('Find the institution location'))
        ->assertDontSee(__('Search like a ride-hailing destination, pick the correct place, then confirm it on the map before submitting.'))
        ->assertSee(__('Search for an institution or address'))
        ->assertDontSee('<label class="text-sm font-medium text-slate-900" for="institution-location-search">', false)
        ->assertSee('element.placeholder =', false)
        ->assertSee('mi-institution-location-picker-search', false)
        ->assertSee('rounded-xl border border-slate-200 bg-white shadow-sm', false)
        ->assertDontSee('pointer-events-none absolute inset-y-0 left-0', false)
        ->assertSee('await this.loadGoogleMaps();', false)
        ->assertSee("element.className = 'block w-full text-sm text-slate-900';", false)
        ->assertSee(__('Google Maps URL'));
});

it('falls back to the manual location fields when google places is disabled', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertDontSee(__('Find the institution location'))
        ->assertSee(__('Google Maps URL'));
});

it('does not seed a country for institution contributions from timezone cookies', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withUnencryptedCookie('user_timezone', 'Asia/Jakarta')
        ->get(route('contributions.submit-institution'))
        ->assertOk();

    Livewire::withCookie('user_timezone', 'Asia/Jakarta')
        ->actingAs($user)
        ->test(SubmitInstitution::class)
        ->assertSet('data.address.country_id', null);
});

it('still requires a selected location when the picker is enabled', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Picker Validation Institution')
        ->set('data.type', 'masjid')
        ->call('submit')
        ->assertHasErrors(['data.address.google_maps_url']);
});

it('keeps manual fallback mode off the places api while still normalizing pasted links locally', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    $country = ensureCountryForLocationPicker('MY', 'Malaysia');

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

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Manual Fallback Maps URL')
        ->set('data.type', 'masjid')
        ->set('data.address.country_id', (string) $country->getKey())
        ->set('data.address.google_maps_url', 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8')
        ->call('submit')
        ->assertHasNoErrors();

    $institution = Institution::query()
        ->with('addresses')
        ->where('name', 'Manual Fallback Maps URL')
        ->first();

    expect($institution)->not->toBeNull()
        ->and($institution?->primaryAddress()?->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=1.9089362%2C102.865462')
        ->and($institution?->primaryAddress()?->google_place_id)->toBeNull()
        ->and((float) $institution?->primaryAddress()?->lat)->toBe(1.9089362)
        ->and((float) $institution?->primaryAddress()?->lng)->toBe(102.865462);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_starts_with((string) $request->url(), 'https://maps.app.goo.gl/'));
});

it('applies a google place selection into the nested institution address state', function () {
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    $state = ensureMalaysiaStateForLocationPicker();
    $district = createTestAddressArea('Petaling', 2, parent: $state['area'], country: $country, type: 'district');
    $subdistrict = createTestAddressArea('Shah Alam', 3, parent: $district, country: $country, type: 'subdistrict');

    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'server-test-key');

    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.address.country_id', (string) $country->getKey())
        ->call('applyPlaceSelection', [
            'placeId' => 'place_abc123',
            'googleMapsURI' => 'https://www.google.com/maps/place/?q=place_id:place_abc123',
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
        ])
        ->assertSet('data.address.country_id', (string) $country->getKey())
        ->assertSet('data.address.line1', 'Persiaran Masjid')
        ->assertSet('data.address.line2', 'Seksyen 14')
        ->assertSet('data.address.postcode', '40000')
        ->assertSet('data.address.state_id', (string) $state['package']->id)
        ->assertSet('data.address.area_assignments.administrative_district', (string) $district->id)
        ->assertSet('data.address.area_assignments.administrative_subdivision', (string) $subdistrict->id)
        ->assertSet('data.address.provider_place_id', 'place_abc123')
        ->assertSet('data.address.google_maps_url', 'https://www.google.com/maps/search/?api=1&query=3.07853%2C101.52073&query_place_id=place_abc123')
        ->assertSet('data.address.google_resolution_source', 'picker')
        ->assertSet('data.address.google_resolution_status', 'resolved')
        ->assertSet('data.address.latitude', 3.07853)
        ->assertSet('data.address.longitude', 101.52073)
        ->assertDontSee(__('Paste the full Google Maps link from your browser'));

    Http::assertNothingSent();
});
