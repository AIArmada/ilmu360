<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Signals\Models\SignalEvent;
use App\Forms\SharedFormSchema;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Livewire\Pages\Contributions\SubmitPerson;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    AddressAreaStateLink::query()->firstOrCreate(
        [
            'address_area_id' => $area->getKey(),
            'state_id' => $packageState->getKey(),
            'hierarchy_type' => 'administrative',
        ],
    );

    return ['package' => $packageState, 'area' => $area];
}

function configureVerifiedGoogleMaps(): void
{
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);
    config()->set('services.google.place_link_resolution_enabled', true);
    config()->set('services.google.places_server_api_key', 'test-server-key');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function verifiedInstitutionPlace(string $placeId, array $overrides = []): array
{
    return array_replace([
        'id' => $placeId,
        'name' => 'places/'.$placeId,
        'displayName' => ['text' => 'Masjid Jamik Ungku Ahmad'],
        'formattedAddress' => 'Jalan Masjid, 40000 Shah Alam, Selangor, Malaysia',
        'addressComponents' => [
            ['longText' => 'Persiaran Masjid', 'shortText' => 'Persiaran Masjid', 'types' => ['route']],
            ['longText' => 'Seksyen 14', 'shortText' => 'Seksyen 14', 'types' => ['sublocality_level_1', 'sublocality', 'political']],
            ['longText' => '40000', 'shortText' => '40000', 'types' => ['postal_code']],
            ['longText' => 'Shah Alam', 'shortText' => 'Shah Alam', 'types' => ['locality', 'political']],
            ['longText' => 'Petaling', 'shortText' => 'Petaling', 'types' => ['administrative_area_level_2', 'political']],
            ['longText' => 'Selangor', 'shortText' => 'Selangor', 'types' => ['administrative_area_level_1', 'political']],
            ['longText' => 'Malaysia', 'shortText' => 'MY', 'types' => ['country', 'political']],
        ],
        'location' => ['latitude' => 3.07853, 'longitude' => 101.52073],
        'googleMapsUri' => 'https://maps.google.com/?cid=11885663895765773631',
        'googleMapsLinks' => [
            'placeUri' => 'https://maps.google.com/?cid=11885663895765773631',
            'directionsUri' => 'https://www.google.com/maps/dir/?api=1&data=!4m2!1s0x808f778c55555555:0xa4f25c571acded3f',
            'writeAReviewUri' => 'https://www.google.com/maps/place/?data=!1s0x808f778c55555555:0xa4f25c571acded3f',
            'reviewsUri' => 'https://www.google.com/maps/place/?data=!1s0x808f778c55555555:0xa4f25c571acded3f',
            'photosUri' => 'https://www.google.com/maps/place/?data=!1s0x808f778c55555555:0xa4f25c571acded3f',
        ],
        'websiteUri' => 'https://93.184.216.34/',
        'internationalPhoneNumber' => '+60 3-1234 5678',
        'regularOpeningHours' => ['weekdayDescriptions' => ['Monday: 8:00 AM–5:00 PM']],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $place
 */
function fakeVerifiedInstitutionPlace(array $place, bool $withHomepage = false): void
{
    $responses = [
        'https://places.googleapis.com/v1/places/*' => Http::response($place, 200),
    ];

    if ($withHomepage) {
        $responses['https://93.184.216.34/*'] = Http::response(
            '<html><a href="https://www.facebook.com/ilmu360/">Facebook</a><a href="https://www.instagram.com/ilmu360/">Instagram</a></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    Http::fake($responses);
}

it('renders the institution location picker when google places is enabled', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertSeeInOrder([
            __('Find the institution on Google Maps'),
            __('Search for an institution or address'),
            __('Institution Profile'),
            __('Address'),
        ])
        ->assertSee(__('Search for an institution or address'))
        ->assertDontSee('<label class="text-sm font-medium text-slate-900" for="institution-location-search">', false)
        ->assertSee('element.placeholder =', false)
        ->assertSee('mi-institution-location-picker-search', false)
        ->assertSee('rounded-xl border border-slate-200 bg-white shadow-sm', false)
        ->assertDontSee('pointer-events-none absolute inset-y-0 left-0', false)
        ->assertSee('await this.loadGoogleMaps();', false)
        ->assertSee("element.className = 'block w-full text-sm text-slate-900';", false)
        ->assertDontSee(__('Google Maps URL'));
});

it('keeps Maps search visible and blocks submission when Google Maps is unavailable', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);

    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertSee(__('Find the institution on Google Maps'))
        ->assertSee(__('Retry Google Maps search'))
        ->assertDontSee(__('Google Maps URL'));

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Maps Required Institution')
        ->call('submit')
        ->assertHasErrors(['data.address.google_place_id']);
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

it('requires a server-verified place selection before submitting', function () {
    configureVerifiedGoogleMaps();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Picker Validation Institution')
        ->set('data.type', 'masjid')
        ->call('submit')
        ->assertHasErrors(['data.address.google_place_id'])
        ->assertSee(__('Select and verify the institution on Google Maps before submitting.'));
});

it('rejects a pasted Maps URL without a verified Maps selection and makes no submit-time lookup', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    Http::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Manual Fallback Maps URL')
        ->set('data.type', 'masjid')
        ->set('data.address.country_id', (string) $country->getKey())
        ->set('data.address.google_maps_url', 'https://maps.app.goo.gl/KWFQuuxAmSK3kRFM8')
        ->call('submit')
        ->assertHasErrors(['data.address.google_place_id']);

    expect(Institution::query()->where('name', 'Manual Fallback Maps URL')->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('rejects a Google response that does not match the selected place id', function () {
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('a-different-place'));

    Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'selected-place'])
        ->assertHasErrors(['data.address.google_place_id']);

    Http::assertSentCount(1);
});

it('rejects a changed place id and an expired verified selection at submission', function () {
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('verified-place', [
        'websiteUri' => null,
    ]));

    $user = User::factory()->create();
    $tampered = Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Tampered Maps Institution')
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'verified-place'])
        ->set('data.address.google_place_id', 'another-place')
        ->call('submit')
        ->assertHasErrors(['data.address.google_place_id']);

    expect(Institution::query()->where('name', 'Tampered Maps Institution')->exists())->toBeFalse();

    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('verified-place', [
        'websiteUri' => null,
    ]));

    $stale = Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Stale Maps Institution')
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'verified-place']);

    Cache::flush();

    $stale->call('submit')
        ->assertHasErrors(['data.address.google_place_id'])
        ->assertSee(__('Select and verify the institution on Google Maps before submitting.'));

    expect(Institution::query()->where('name', 'Stale Maps Institution')->exists())->toBeFalse();
});

it('verifies a Maps place, autofills public links, and persists provider identifiers and hierarchy data', function () {
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    $state = ensureMalaysiaStateForLocationPicker();
    $district = createTestAddressArea('Petaling', 2, parent: $state['area'], country: $country, type: 'district');
    $subdistrict = createTestAddressArea('Shah Alam', 3, parent: $district, country: $country, type: 'subdistrict');

    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_abc123'), withHomepage: true);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_abc123'])
        ->assertHasNoErrors()
        ->assertSet('data.name', 'Masjid Jamik Ungku Ahmad')
        ->assertSet('data.address.country_id', (string) $country->getKey())
        ->assertSet('data.address.line1', 'Persiaran Masjid')
        ->assertSet('data.address.line2', 'Seksyen 14')
        ->assertSet('data.address.postcode', '40000')
        ->assertSet('data.address.state_id', (string) $state['package']->id)
        ->assertSet('data.address.area_assignments.administrative_district', (string) $district->id)
        ->assertSet('data.address.area_assignments.administrative_subdivision', (string) $subdistrict->id)
        ->assertSet('data.address.google_place_id', 'place_abc123')
        ->assertSet('data.address.google_maps_url', 'https://www.google.com/maps/search/?api=1&query=3.07853%2C101.52073&query_place_id=place_abc123')
        ->assertSet('data.address.google_resolution_source', 'picker')
        ->assertSet('data.address.google_resolution_status', 'resolved')
        ->assertSet('data.address.latitude', 3.07853)
        ->assertSet('data.address.longitude', 101.52073)
        ->assertSet('data.type', 'masjid')
        ->assertSet('data.contactMethods.0.value', 'https://93.184.216.34/')
        ->assertSet('data.contactMethods.1.phone_value', '+60 3-1234 5678')
        ->assertSet('data.social_media.0.platform', 'facebook')
        ->assertSet('data.social_media.0.handle', 'ilmu360')
        ->assertSet('data.social_media.1.platform', 'instagram')
        ->assertSet('data.social_media.1.handle', 'ilmu360')
        ->assertDontSee(__('Paste the full Google Maps link from your browser'))
        ->call('submit')
        ->assertHasNoErrors();

    $institution = Institution::query()->where('name', 'Masjid Jamik Ungku Ahmad')->firstOrFail();
    $address = $institution->primaryAddress();

    expect($address?->google_place_id)->toBe('place_abc123')
        ->and($address?->google_feature_id)->toBe('0x808f778c55555555:0xa4f25c571acded3f')
        ->and($address?->google_cid)->toBe('11885663895765773631')
        ->and($address?->google_entity_id)->toBeNull()
        ->and(data_get($address?->metadata, 'google_places.resource_name'))->toBe('places/place_abc123')
        ->and(data_get($address?->metadata, 'google_places.place.regularOpeningHours.weekdayDescriptions.0'))->toBe('Monday: 8:00 AM–5:00 PM')
        ->and(data_get($address?->navigation_links, 'directionsUri.url'))->toContain('/maps/dir/')
        ->and(data_get($address?->components, '6.types'))->toContain('country')
        ->and($address?->formatted_address)->toBe('Jalan Masjid, 40000 Shah Alam, Selangor, Malaysia')
        ->and(data_get($address?->areaAssignments()->pluck('role', 'address_area_id')->all(), (string) $district->id))->toBe('administrative_district')
        ->and(data_get($address?->areaAssignments()->pluck('role', 'address_area_id')->all(), (string) $subdistrict->id))->toBe('administrative_subdivision');

    expect($institution->contactMethods()->where('type', 'website')->value('value'))->toBe('https://93.184.216.34/')
        ->and($institution->contactMethods()->where('type', 'phone')->value('value'))->toBe('+60312345678')
        ->and($institution->socialProfiles()->where('platform', 'facebook')->value('handle'))->toBe('ilmu360')
        ->and($institution->socialProfiles()->where('platform', 'instagram')->value('handle'))->toBe('ilmu360');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://places.googleapis.com/v1/places/place_abc123')
        && $request->hasHeader('X-Goog-FieldMask', '*')
        && $request->hasHeader('X-Goog-Api-Key', 'test-server-key'));

    $signal = SignalEvent::query()->where('event_name', 'contribution.institution_maps_place_selected')->sole();

    expect(data_get($signal->properties, 'has_website'))->toBeTrue()
        ->and(data_get($signal->properties, 'social_profile_count'))->toBe(2);
});

it('suggests a type from the selected Maps place without overwriting a contributor correction', function () {
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_surau_type', [
        'displayName' => ['text' => 'Surau Al-Hidayah'],
        'primaryType' => 'mosque',
        'websiteUri' => null,
    ]));

    $component = Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_surau_type'])
        ->assertSet('data.type', 'surau');

    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_generic_mosque_type', [
        'displayName' => ['text' => 'Sultan Salahuddin Shah'],
        'primaryType' => 'mosque',
        'websiteUri' => null,
    ]));

    $component->set('data.type', 'pondok')
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_generic_mosque_type'])
        ->assertSet('data.type', 'pondok');
});

it('leaves an unknown Maps place type for the contributor to choose', function () {
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_unclassified_type', [
        'displayName' => ['text' => 'Community Hall'],
        'primaryType' => 'community_center',
        'types' => ['point_of_interest', 'establishment'],
        'websiteUri' => null,
    ]));

    Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_unclassified_type'])
        ->assertHasNoErrors()
        ->assertSet('data.type', null);
});

it('loads attributed Google photo previews only after verifying the selected place', function () {
    configureVerifiedGoogleMaps();
    $place = verifiedInstitutionPlace('place_with_photo', [
        'websiteUri' => null,
        'photos' => [[
            'name' => 'places/place_with_photo/photos/photo-one',
            'widthPx' => 800,
            'heightPx' => 600,
            'googleMapsUri' => 'https://maps.google.com/?cid=123',
            'authorAttributions' => [['displayName' => 'Photo contributor']],
        ]],
    ]);
    Http::fake([
        'https://places.googleapis.com/v1/places/place_with_photo/photos/*/media*' => Http::response([
            'photoUri' => 'https://lh3.googleusercontent.com/photo-one=s800',
        ], 200),
        'https://places.googleapis.com/v1/places/*' => Http::response($place, 200),
    ]);

    $component = Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_with_photo'])
        ->assertReturned(fn (array $selection): bool => ($selection['google_place_id'] ?? null) === 'place_with_photo');

    Http::assertSentCount(1);

    $component->call('loadPlacePhotoPreviews', 'another_place')
        ->assertReturned([]);

    Http::assertSentCount(1);

    $component->call('loadPlacePhotoPreviews', 'place_with_photo')
        ->assertReturned([[
            'preview_url' => 'https://lh3.googleusercontent.com/photo-one=s800',
            'width' => 800,
            'height' => 600,
            'attributions' => [['display_name' => 'Photo contributor', 'uri' => null]],
            'google_maps_uri' => 'https://maps.google.com/?cid=123',
        ]]);

    Http::assertSentCount(3);
});

it('preserves contributor address corrections after Google Maps verification', function () {
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam');
    $locality = createTestAddressArea('Taman Manual Contribution', 3, country: $geo['country'], type: 'locality');
    AddressAreaStateLink::query()->firstOrCreate(
        [
            'address_area_id' => $geo['area_tree_root']->getKey(),
            'state_id' => $geo['state']->getKey(),
            'hierarchy_type' => 'postal',
        ],
    );
    AddressAreaRelationship::query()->firstOrCreate(
        [
            'parent_address_area_id' => $geo['area_tree_root']->getKey(),
            'child_address_area_id' => $locality->getKey(),
        ],
        [
            'relationship_type' => 'contains',
            'hierarchy_type' => 'postal',
            'source' => 'tests',
        ],
    );

    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_manual_correction', [
        'displayName' => ['text' => 'Masjid Sultan Salahuddin Abdul Aziz Shah'],
        'addressComponents' => [
            ['longText' => 'Masjid Sultan Salahuddin Abdul Aziz Shah', 'shortText' => 'Masjid Sultan Salahuddin Abdul Aziz Shah', 'types' => ['premise']],
            ['longText' => 'Seksyen 14', 'shortText' => 'Seksyen 14', 'types' => ['sublocality_level_1', 'sublocality', 'political']],
            ['longText' => '40000', 'shortText' => '40000', 'types' => ['postal_code']],
            ['longText' => 'Shah Alam', 'shortText' => 'Shah Alam', 'types' => ['locality', 'political']],
            ['longText' => 'Petaling', 'shortText' => 'Petaling', 'types' => ['administrative_area_level_2', 'political']],
            ['longText' => 'Selangor', 'shortText' => 'Selangor', 'types' => ['administrative_area_level_1', 'political']],
            ['longText' => 'Malaysia', 'shortText' => 'MY', 'types' => ['country', 'political']],
        ],
        'websiteUri' => null,
    ]));

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Masjid Sultan Salahuddin Abdul Aziz Shah')
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_manual_correction'])
        ->assertSet('data.address.line1', null)
        ->set('data.address.line1', '')
        ->set('data.address.postcode', '40001')
        ->set('data.address.area_assignments.postal_locality', (string) $locality->getKey())
        ->call('submit')
        ->assertHasNoErrors();

    $institution = Institution::query()->where('name', 'Masjid Sultan Salahuddin Abdul Aziz Shah')->firstOrFail();
    $address = $institution->primaryAddress();
    $request = ContributionRequest::query()->where('entity_id', $institution->getKey())->firstOrFail();

    expect($address?->line1)->toBeNull()
        ->and($address?->postcode)->toBe('40001')
        ->and($address?->areaAssignments()->where('role', 'postal_locality')->value('address_area_id'))->toBe((string) $locality->getKey())
        ->and(data_get($request->proposed_data, 'address.line1'))->toBeNull()
        ->and(data_get($request->proposed_data, 'address.postcode'))->toBe('40001')
        ->and(data_get($request->proposed_data, 'address.area_assignments.postal_locality'))->toBe((string) $locality->getKey());
});

it('follows bounded public website redirects when discovering social profiles', function () {
    configureVerifiedGoogleMaps();
    $place = verifiedInstitutionPlace('place_redirected_homepage', [
        'websiteUri' => 'http://93.184.216.34/',
    ]);
    Http::fake([
        'https://places.googleapis.com/v1/places/*' => Http::response($place, 200),
        'http://93.184.216.34/*' => Http::response('', 301, ['Location' => 'https://93.184.216.34/']),
        'https://93.184.216.34/*' => Http::response(
            '<html><a href="https://www.facebook.com/redirected-masjid/">Facebook</a></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_redirected_homepage'])
        ->assertHasNoErrors()
        ->assertSet('data.social_media.0.platform', 'facebook')
        ->assertSet('data.social_media.0.handle', 'redirected-masjid');

    Http::assertSentCount(3);
});

it('keeps a verified Maps selection when optional website enrichment fails', function () {
    configureVerifiedGoogleMaps();
    $place = verifiedInstitutionPlace('place_failed_homepage');
    Http::fake([
        'https://places.googleapis.com/v1/places/*' => Http::response($place, 200),
        'https://93.184.216.34/*' => Http::response('unavailable', 503),
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_failed_homepage'])
        ->assertHasNoErrors()
        ->assertSet('data.address.google_place_id', 'place_failed_homepage')
        ->assertSet('data.contactMethods.0.value', 'https://93.184.216.34/')
        ->assertSet('data.social_media', []);
});

it('applies a google place selection from the person contribution institution picker', function () {
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('person_place_123', [
        'displayName' => ['text' => 'Masjid Ilmu'],
        'addressComponents' => [
            ['longText' => 'Jalan Ilmu', 'shortText' => 'Jalan Ilmu', 'types' => ['route']],
            ['longText' => '50000', 'shortText' => '50000', 'types' => ['postal_code']],
            ['longText' => 'Malaysia', 'shortText' => 'MY', 'types' => ['country', 'political']],
        ],
    ]));
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitPerson::class)
        ->set('data.address.country_id', (string) $country->getKey())
        ->call('applyLocationPickerSelection', 'data.address', [
            'placeId' => 'person_place_123',
        ])
        ->assertSet('data.address.country_id', (string) $country->getKey())
        ->assertSet('data.address.line1', 'Jalan Ilmu')
        ->assertSet('data.address.postcode', '50000')
        ->assertSet('data.address.google_place_id', 'person_place_123')
        ->assertSet('data.address.google_cid', '11885663895765773631')
        ->assertSet('data.address.google_resolution_source', 'picker')
        ->assertSet('data.address.latitude', 3.07853)
        ->assertSet('data.address.longitude', 101.52073);
});

it('keeps the area assignment keys present when a place resolves no areas', function () {
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_no_areas', [
        'addressComponents' => [
            ['longText' => 'Malaysia', 'shortText' => 'MY', 'types' => ['country', 'political']],
        ],
        'websiteUri' => null,
    ]));

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.address.country_id', (string) $country->getKey())
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_no_areas'])
        ->assertSet('data.address.area_assignments', array_fill_keys(SharedFormSchema::entryAreaRoles(), null));
});

it('persists the resolved city id when the city field is hidden', function () {
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    $state = ensureMalaysiaStateForLocationPicker('Wilayah Persekutuan');
    $state['package']->update(['code' => '14']);

    $city = City::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state['package']->getKey(),
        'name' => 'Kuala Lumpur',
    ]);

    $user = User::factory()->create();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_city_only', [
        'displayName' => ['text' => 'Hidden City Persistence Institution'],
        'addressComponents' => [
            ['longText' => 'Kuala Lumpur', 'shortText' => 'Kuala Lumpur', 'types' => ['locality', 'political']],
            ['longText' => 'Wilayah Persekutuan', 'shortText' => 'Wilayah Persekutuan', 'types' => ['administrative_area_level_1', 'political']],
            ['longText' => 'Malaysia', 'shortText' => 'MY', 'types' => ['country', 'political']],
        ],
        'websiteUri' => null,
    ]));

    Livewire::actingAs($user)
        ->test(SubmitInstitution::class)
        ->set('data.name', 'Hidden City Persistence Institution')
        ->set('data.type', 'masjid')
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_city_only'])
        ->assertSet('data.address.city_id', (string) $city->getKey())
        ->call('submit')
        ->assertHasNoErrors();

    $institution = Institution::query()
        ->with('addresses')
        ->where('name', 'Hidden City Persistence Institution')
        ->first();

    expect($institution)->not->toBeNull()
        ->and($institution?->primaryAddress()?->city_id)->toBe((string) $city->getKey())
        ->and($institution?->primaryAddress()?->state_id)->toBe((string) $state['package']->getKey());
});
