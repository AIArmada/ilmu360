<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\AddressCountry;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Signals\Models\SignalEvent;
use App\Actions\Contributions\Review\ApproveContributionRequestAction;
use App\Actions\Contributions\Review\RejectContributionRequestAction;
use App\Features\Directory\Api\DirectoryContributionMedia;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Livewire\Pages\Contributions\SubmitPerson;
use App\Livewire\Pages\Contributions\SuggestUpdate;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Shared\Directory\Enums\DonationChannelStatus;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

function giveInstitutionStoredMapsPlace(Institution $institution, string $placeId = 'place_stored_existing'): void
{
    $address = $institution->primaryAddress();

    if ($address === null) {
        return;
    }

    $address->forceFill([
        'google_place_id' => $placeId,
        'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query_place_id='.$placeId,
    ])->save();
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
        ->assertSee(__('Loading place details from Google Maps…'))
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
        ->assertSet('data.address.line2', null)
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
        ->assertSet('data.address.line1', 'Seksyen 14')
        ->assertSet('data.address.line2', null)
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

it('renders the optional location reselect section on the institution update page', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $user, MemberRole::Owner));

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSeeInOrder([
            __('Update the location on Google Maps'),
            __('Institution Profile'),
            __('Address'),
            __('Cover Image'),
            __('Donation Channels'),
        ])
        ->assertDontSee(__('Find the institution on Google Maps'))
        ->assertDontSee(__('Google Maps URL'))
        ->assertSee(__('Loading place details from Google Maps…'));
});

it('keeps Media after Address on the institution update page when places is disabled', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);

    $user = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $user, MemberRole::Owner));

    $this->actingAs($user);

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSeeInOrder([
            __('Institution Profile'),
            __('Address'),
            __('Cover Image'),
            __('Donation Channels'),
        ])
        ->assertDontSee(__('Update the location on Google Maps'))
        ->assertDontSee(__('Search for an institution or address'))
        ->assertDontSee(__('Google Maps URL'));
});

it('submits an institution name update without Maps re-verification', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'name' => 'Masjid Lama',
    ]);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.name', 'Masjid Baharu')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->name)->toBe('Masjid Baharu');
});

it('does not stage or delete live institution media on visitor text-only updates', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $visitor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);

    $component = Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ]);

    // A maintainer adds a logo after the visitor mounted the page.
    $logo = fakeGeneratedImageUpload('owner-logo.png', 640, 640);
    $institution->addMediaFromString((string) file_get_contents($logo->getRealPath()))
        ->usingFileName('owner-logo.png')
        ->toMediaCollection('logo');

    $logoId = $institution->fresh()->getFirstMedia('logo')?->getKey();
    expect($logoId)->not->toBeNull();

    $component
        ->set('data.name', 'Masjid Baharu')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->getMedia('logo'))->toHaveCount(1)
        ->and($institution->fresh()->getFirstMedia('logo')?->getKey())->toBe($logoId);

    $request = ContributionRequest::query()
        ->where('entity_id', $institution->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($request->getMedia('pending_media'))->toHaveCount(0);
});

it('handles donation QR uploads stored under Filament file keys', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);

    $component = Livewire::actingAs($user)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
            'ewallet_handle' => '0912345678',
        ]])
        ->upload('data.donation_channels.0.qr_upload', [File::fake()->image('keyed-qr.png', 600, 600)], true);

    $uploaded = $component->get('data.donation_channels.0.qr_upload');
    $tuf = is_array($uploaded) ? $uploaded[array_key_first($uploaded)] : $uploaded;
    expect($tuf)->toBeInstanceOf(TemporaryUploadedFile::class);

    // Production Filament state keys the file by its generated file key.
    $keyedRows = [[
        'recipient' => 'Jawatankuasa Surau',
        'method' => 'ewallet',
        'ewallet_provider' => 'tng',
        'ewallet_handle' => '0912345678',
        'qr_upload' => ['fileKey123' => $tuf],
    ]];

    expect(SharedFormSchema::collectDonationQrUploads($keyedRows))->toHaveCount(1);

    app(DirectoryContributionMedia::class)->assertDonationQrUploadsFresh($keyedRows);

    Storage::disk('tmp-for-tests')->deleteDirectory('livewire-tmp');

    expect(fn () => app(DirectoryContributionMedia::class)->assertDonationQrUploadsFresh($keyedRows))
        ->toThrow(ValidationException::class);
});

it('rejects an unverified Google place change on the institution update page', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.address.google_place_id', 'unverified-place')
        ->call('submit')
        ->assertHasErrors(['data.address.google_place_id'])
        ->assertSee(__('Select and verify the new location on Google Maps before submitting.'));
});

it('verifies a Maps reselect and persists provider identifiers on the institution update page', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_reselect_abc', [
        'websiteUri' => null,
    ]));

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'name' => 'Masjid Reselect',
    ]);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_reselect_abc'])
        ->assertHasNoErrors()
        ->assertSet('data.address.google_place_id', 'place_reselect_abc')
        ->assertSet('data.name', 'Masjid Jamik Ungku Ahmad')
        ->call('submit')
        ->assertHasNoErrors();

    $address = $institution->fresh()->primaryAddress();

    expect($address?->google_place_id)->toBe('place_reselect_abc')
        ->and($address?->google_cid)->toBe('11885663895765773631')
        ->and($institution->fresh()->contactMethods()->where('type', 'phone')->pluck('value')->all())->toContain('+60312345678')
        ->and(SignalEvent::query()->where('event_name', 'contribution.institution_maps_place_selected')->count())->toBe(1);
});

it('creates an update request with a verified Maps reselect for visitors', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_visitor_reselect', [
        'websiteUri' => null,
    ]));

    $visitor = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_visitor_reselect'])
        ->assertHasNoErrors()
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()
        ->where('entity_id', $institution->getKey())
        ->latest('id')
        ->firstOrFail();

    expect(data_get($request->proposed_data, 'address.google_place_id'))->toBe('place_visitor_reselect');
});

it('blocks moving an institution onto another institution’s Google Maps place', function () {
    $geography = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam');
    $claimed = Institution::factory()->create(['status' => 'verified']);
    SharedFormSchema::createAddressFromData($claimed, [
        'country_id' => (string) $geography['country']->getKey(),
        'google_place_id' => 'place_taken_abc',
        'google_cid' => '11885663895765773631',
        'google_maps_url' => 'https://maps.google.com/?cid=11885663895765773631',
    ], allowCountryOnly: true);

    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_taken_abc', [
        'websiteUri' => null,
    ]));

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_taken_abc'])
        ->assertHasNoErrors()
        ->call('submit')
        ->assertHasErrors(['data.address.google_place_id'])
        ->assertSee(__('Another institution already uses this Google Maps place.'));

    expect($institution->fresh()->primaryAddress()?->google_place_id)->not->toBe('place_taken_abc');
});

it('shows the donation channels section on both institution contribution pages', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);

    $this->actingAs($user);

    $this->get(route('contributions.submit-institution'))
        ->assertOk()
        ->assertSee(__('Donation Channels'))
        ->assertSee(__('Share official donation accounts for this institution.'))
        ->assertSee(__('Add donation channel'));

    $this->get(route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
        'subjectId' => $institution->slug,
    ]))
        ->assertOk()
        ->assertSee(__('Donation Channels'))
        ->assertSee(__('Share official donation accounts for this institution.'))
        ->assertSee(__('Add donation channel'));
});

it('stores donation channels when submitting a new institution', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_donation_create', [
        'websiteUri' => null,
    ]));

    Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_donation_create'])
        ->assertHasNoErrors()
        ->set('data.type', 'masjid')
        ->set('data.donation_channels', [[
            'label' => 'Tabung Masjid',
            'recipient' => 'Masjid Jamik Ungku Ahmad',
            'method' => 'bank_account',
            'bank_name' => 'Maybank',
            'bank_code' => 'MBB',
            'account_number' => '1234567890',
            'reference_note' => 'Dana am',
            'is_default' => true,
        ]])
        ->call('submit')
        ->assertHasNoErrors();

    $institution = Institution::query()->where('name', 'Masjid Jamik Ungku Ahmad')->firstOrFail();
    $channel = $institution->donationChannels()->firstOrFail();

    expect($institution->donationChannels()->count())->toBe(1)
        ->and($channel->method)->toBe('bank_account')
        ->and($channel->account_number)->toBe('1234567890')
        ->and($channel->status)->toBe(DonationChannelStatus::Pending)
        ->and($channel->is_default)->toBeTrue();
});

it('applies donation channel additions on direct institution edits', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'label' => 'Dana Pembangunan',
            'recipient' => 'Jawatankuasa Masjid',
            'method' => 'duitnow',
            'duitnow_type' => 'business',
            'duitnow_value' => '12345678',
            'is_default' => true,
        ]])
        ->call('submit')
        ->assertHasNoErrors();

    $channel = $institution->fresh()->donationChannels()->firstOrFail();

    expect($channel->method)->toBe('duitnow')
        ->and($channel->duitnow_value)->toBe('12345678')
        ->and($channel->status)->toBe(DonationChannelStatus::Pending);
});

it('preserves verified donation channels on unrelated institution edits', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified', 'name' => 'Masjid Kekal']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    $channel = $institution->donationChannels()->create([
        'label' => 'Tabung Masjid',
        'recipient' => 'Masjid Kekal',
        'method' => 'bank_account',
        'bank_name' => 'Maybank',
        'account_number' => '0987654321',
        'is_default' => true,
    ]);
    $channel->verify($owner);

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.name', 'Masjid Kekal Baharu')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->name)->toBe('Masjid Kekal Baharu')
        ->and($institution->donationChannels()->count())->toBe(1);

    $fresh = $institution->donationChannels()->firstOrFail();

    expect($fresh->getKey())->toBe($channel->getKey())
        ->and($fresh->status)->toBe(DonationChannelStatus::Verified)
        ->and($fresh->verified_by)->toBe($owner->getKey());
});

it('stages donation channel proposals on visitor update requests', function () {
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
            'ewallet_handle' => '0912345678',
        ]])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()
        ->where('entity_id', $institution->getKey())
        ->latest('id')
        ->firstOrFail();

    expect(data_get($request->proposed_data, 'donation_channels.0.method'))->toBe('ewallet')
        ->and(data_get($request->proposed_data, 'donation_channels.0.ewallet_handle'))->toBe('0912345678')
        ->and($institution->fresh()->donationChannels()->count())->toBe(0);
});

it('fills untouched name and type when reselecting a Maps place on update', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_autofill_update', [
        'websiteUri' => null,
    ]));

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'type' => 'surau',
        'name' => 'Surau Lama',
    ]);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_autofill_update'])
        ->assertHasNoErrors()
        ->assertSet('data.name', 'Masjid Jamik Ungku Ahmad')
        ->assertSet('data.type', 'masjid');
});

it('preserves user-edited name and type when reselecting a Maps place on update', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_autofill_edited', [
        'websiteUri' => null,
    ]));

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'type' => 'surau',
        'name' => 'Surau Lama',
    ]);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.name', 'Nama Pilihan Sendiri')
        ->set('data.type', 'pondok')
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_autofill_edited'])
        ->assertHasNoErrors()
        ->assertSet('data.name', 'Nama Pilihan Sendiri')
        ->assertSet('data.type', 'pondok');
});

it('loads attributed photo previews after a verified reselect on update', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    $place = verifiedInstitutionPlace('place_reselect_photo', [
        'websiteUri' => null,
        'photos' => [[
            'name' => 'places/place_reselect_photo/photos/photo-one',
            'widthPx' => 800,
            'heightPx' => 600,
            'googleMapsUri' => 'https://maps.google.com/?cid=123',
            'authorAttributions' => [['displayName' => 'Photo contributor']],
        ]],
    ]);
    Http::fake([
        'https://places.googleapis.com/v1/places/place_reselect_photo/photos/*/media*' => Http::response([
            'photoUri' => 'https://lh3.googleusercontent.com/photo-one=s800',
        ], 200),
        'https://places.googleapis.com/v1/places/*' => Http::response($place, 200),
    ]);

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    $component = Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_reselect_photo'])
        ->assertHasNoErrors();

    $component->call('loadPlacePhotoPreviews', 'another_place')
        ->assertReturned([]);

    $component->call('loadPlacePhotoPreviews', 'place_reselect_photo')
        ->assertReturned([[
            'preview_url' => 'https://lh3.googleusercontent.com/photo-one=s800',
            'width' => 800,
            'height' => 600,
            'attributions' => [['display_name' => 'Photo contributor', 'uri' => null]],
            'google_maps_uri' => 'https://maps.google.com/?cid=123',
        ]]);
});

it('keeps the stored type when the reselected place has no inferable type', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_unclassified_update', [
        'displayName' => ['text' => 'Community Hall'],
        'primaryType' => 'community_center',
        'types' => ['point_of_interest', 'establishment'],
        'websiteUri' => null,
    ]));

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'type' => 'surau',
        'name' => 'Surau Lama',
    ]);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_unclassified_update'])
        ->assertHasNoErrors()
        ->assertSet('data.name', 'Community Hall')
        ->assertSet('data.type', 'surau');
});

it('requires a Maps place when updating an institution without one', function () {
    config()->set('services.google.maps_api_key', 'test-maps-key');
    config()->set('services.google.places_enabled', true);

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.name', 'Masjid Tanpa Pin')
        ->call('submit')
        ->assertHasErrors(['data.address.google_place_id'])
        ->assertSee(__('Select and verify the institution on Google Maps before submitting.'));
});

it('skips autofilled contacts that already exist when reselecting', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_dedupe_contact', [
        'websiteUri' => 'https://93.184.216.34/',
    ]));

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.contactMethods', [[
            'type' => 'website',
            'value' => 'https://93.184.216.34/',
            'purpose' => 'general',
            'is_primary' => false,
            'is_public' => true,
        ]])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_dedupe_contact'])
        ->assertHasNoErrors()
        ->assertSet('data.contactMethods', fn (mixed $rows): bool => is_array($rows)
            && count($rows) === 2
            && collect($rows)->where('type', 'website')->count() === 1
            && collect($rows)->where('type', 'phone')->count() === 1);
});

it('skips autofilled social profiles that already exist when reselecting', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_dedupe_social'), withHomepage: true);

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.social_media', [[
            'platform' => 'facebook',
            'handle' => 'ilmu360',
            'url' => null,
            'is_primary' => false,
            'is_public' => true,
        ]])
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_dedupe_social'])
        ->assertHasNoErrors()
        ->assertSet('data.social_media', fn (mixed $rows): bool => is_array($rows)
            && count($rows) === 2
            && collect($rows)->where('platform', 'facebook')->count() === 1
            && collect($rows)->where('platform', 'instagram')->count() === 1);
});

it('stores donation QR uploads when submitting a new institution', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_qr_create', [
        'websiteUri' => null,
    ]));

    Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_qr_create'])
        ->assertHasNoErrors()
        ->set('data.type', 'masjid')
        ->set('data.donation_channels', [[
            'label' => 'Tabung Masjid',
            'recipient' => 'Masjid Jamik Ungku Ahmad',
            'method' => 'bank_account',
            'bank_name' => 'Maybank',
            'account_number' => '1234567890',
        ]])
        ->upload('data.donation_channels.0.qr_upload', [File::fake()->image('donation-qr.png', 600, 600)], true)
        ->call('submit')
        ->assertHasNoErrors();

    $institution = Institution::query()->where('name', 'Masjid Jamik Ungku Ahmad')->firstOrFail();
    $channel = $institution->donationChannels()->firstOrFail();

    expect($channel->getFirstMedia('qr'))->not->toBeNull()
        ->and($channel->getFirstMedia('qr')->file_name)->toMatch('/^tabung-masjid-[0-9a-z]{8}\.png$/')
        ->and($channel->getFirstMedia('qr')->name)->toBe('QR Code - Tabung Masjid');
});

it('attaches donation QR uploads on direct institution edits', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Masjid',
            'method' => 'duitnow',
            'duitnow_type' => 'business',
            'duitnow_value' => '12345678',
        ]])
        ->upload('data.donation_channels.0.qr_upload', [File::fake()->image('duitnow-qr.png', 600, 600)], true)
        ->call('submit')
        ->assertHasNoErrors();

    $channel = $institution->fresh()->donationChannels()->firstOrFail();

    expect($channel->getFirstMedia('qr'))->not->toBeNull()
        ->and($channel->getFirstMedia('qr')->file_name)->toMatch('/^[a-z0-9_-]+-[0-9a-z]{8}\.png$/');
});

it('stages visitor donation QR uploads and attaches them on approval', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $visitor = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
            'ewallet_handle' => '0912345678',
        ]])
        ->upload('data.donation_channels.0.qr_upload', [File::fake()->image('visitor-qr.png', 600, 600)], true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('contributions.index'));

    $request = ContributionRequest::query()
        ->where('entity_id', $institution->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($institution->fresh()->donationChannels()->count())->toBe(0);

    $staged = $request->getMedia('pending_media');

    expect($staged)->toHaveCount(1)
        ->and($staged->first()->getCustomProperty('contribution_field'))->toBe('donation_qr')
        ->and($staged->first()->getCustomProperty('donation_fingerprint'))->toBe('sha256:'.hash('sha256', 'ewallet|tng|0912345678|'));

    app(ApproveContributionRequestAction::class)->handle($request->fresh(), $reviewer);

    $channel = $institution->fresh()->donationChannels()->firstOrFail();

    expect($channel->getFirstMedia('qr'))->not->toBeNull()
        ->and($channel->getFirstMedia('qr')->file_name)->toMatch('/^[a-z0-9_-]+-[0-9a-z]{8}\.png$/')
        ->and($request->fresh()->getMedia('pending_media'))->toHaveCount(0);
});

// --- Joint audit hardening: visitor media policy, QR atomicity, sync guards ---

it('hides the institution media section from visitors on the update page', function () {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->assertDontSee(__('Logo'))
        ->assertDontSee(__('Cover Image'))
        ->assertDontSee(__('Gallery'));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->assertSee(__('Logo'))
        ->assertSee(__('Cover Image'))
        ->assertSee(__('Gallery'));
});

it('does not create a pending request when the donation QR upload expires', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $visitor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);

    $component = Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
            'ewallet_handle' => '0912345678',
        ]])
        ->upload('data.donation_channels.0.qr_upload', [File::fake()->image('visitor-qr.png', 600, 600)], true);

    // Expire the temporary upload before submitting. Livewire testing stores
    // temporary uploads on the auto-faked `tmp-for-tests` disk.
    Storage::disk('tmp-for-tests')->deleteDirectory('livewire-tmp');

    $component->call('submit')
        ->assertHasErrors(['data.donation_channels.0.qr_upload']);

    expect(ContributionRequest::query()->where('entity_id', $institution->getKey())->count())->toBe(0);
});

it('maps dehydrated donation row indices back to raw repeater keys', function () {
    $dehydrated = [
        0 => ['recipient' => 'Tabung A'],
        1 => ['recipient' => 'Tabung B'],
    ];

    expect(array_keys(SharedFormSchema::rekeyDonationRowsByRawKeys($dehydrated, [
        'uuid-row-1' => ['recipient' => 'Tabung A'],
        'uuid-row-2' => ['recipient' => 'Tabung B'],
    ])))->toBe(['uuid-row-1', 'uuid-row-2']);

    expect(array_keys(SharedFormSchema::rekeyDonationRowsByRawKeys($dehydrated, [
        ['recipient' => 'Tabung A'],
        ['recipient' => 'Tabung B'],
    ])))->toBe([0, 1]);
});

it('requires an e-wallet handle or QR payload when submitting donations', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
        ]])
        ->call('submit')
        ->assertHasErrors([
            'data.donation_channels.0.ewallet_handle',
            'data.donation_channels.0.ewallet_qr_payload',
        ]);
});

it('stores an ewallet channel identified only by its QR payload', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
            'ewallet_qr_payload' => 'https://qr.tngdigital.com.my/abc123',
        ]])
        ->call('submit')
        ->assertHasNoErrors();

    $channel = $institution->fresh()->donationChannels()->firstOrFail();

    expect($channel->ewallet_provider)->toBe('tng')
        ->and($channel->ewallet_qr_payload)->toBe('https://qr.tngdigital.com.my/abc123');
});

it('deletes the donation channel and its QR media when the row is removed', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Masjid',
            'method' => 'duitnow',
            'duitnow_type' => 'business',
            'duitnow_value' => '12345678',
        ]])
        ->upload('data.donation_channels.0.qr_upload', [File::fake()->image('duitnow-qr.png', 600, 600)], true)
        ->call('submit')
        ->assertHasNoErrors();

    $channel = $institution->fresh()->donationChannels()->firstOrFail();
    $qrMediaId = $channel->getFirstMedia('qr')?->getKey();

    expect($qrMediaId)->not->toBeNull();

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [])
        ->call('submit')
        ->assertHasNoErrors();

    expect(DonationChannel::query()->whereKey($channel->getKey())->exists())->toBeFalse()
        ->and(Media::query()->whereKey($qrMediaId)->exists())->toBeFalse();
});

it('restores tampered Google identity fields when the place is unchanged', function () {
    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    $address = $institution->primaryAddress();
    $address->forceFill(['google_cid' => '11885663895765773631'])->save();

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.name', 'Masjid Baharu')
        ->set('data.address.google_cid', 'tampered-cid')
        ->call('submit')
        ->assertHasNoErrors();

    expect($institution->fresh()->primaryAddress()?->google_cid)->toBe('11885663895765773631');
});

it('refuses to wipe all donation channels when every submitted row is invalid', function () {
    $institution = Institution::factory()->create(['status' => 'verified']);
    withGlobalOwnerContext(function () use ($institution): void {
        $institution->donationChannels()->create([
            'recipient' => 'Jawatankuasa Masjid',
            'method' => 'bank_account',
            'bank_name' => 'Maybank',
            'account_number' => '12345678',
        ]);
    });

    expect(fn () => app(ContributionEntityMutationService::class)->apply($institution, [
        'donation_channels' => [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
        ]],
    ]))->toThrow(ValidationException::class);

    expect($institution->fresh()->donationChannels()->count())->toBe(1);
});

it('shows a form-level error when Maps is unavailable for a place-less institution', function () {
    config()->set('services.google.maps_api_key');
    config()->set('services.google.places_enabled', false);

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.name', 'Masjid Baharu')
        ->call('submit')
        ->assertHasErrors(['data']);
});

it('throttles repeated Google place selections', function () {
    ensureCountryForLocationPicker('MY', 'Malaysia');
    ensureMalaysiaStateForLocationPicker();
    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('place_throttle_x', [
        'websiteUri' => null,
    ]));

    $owner = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    $test = Livewire::actingAs($owner)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ]);

    for ($i = 0; $i < 30; $i++) {
        $test->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_throttle_x']);
    }

    $test->call('applyPlaceSelection', 'data.address', ['placeId' => 'place_throttle_x'])
        ->assertHasErrors(['data.address.google_place_id']);
});

it('clears staged donation QR media when a contribution request is rejected', function () {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');

    $visitor = User::factory()->create();
    $reviewer = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    giveInstitutionStoredMapsPlace($institution);

    Livewire::actingAs($visitor)
        ->test(SuggestUpdate::class, [
            'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
            'subjectId' => $institution->slug,
        ])
        ->set('data.donation_channels', [[
            'recipient' => 'Jawatankuasa Surau',
            'method' => 'ewallet',
            'ewallet_provider' => 'tng',
            'ewallet_handle' => '0912345678',
        ]])
        ->upload('data.donation_channels.0.qr_upload', [File::fake()->image('visitor-qr.png', 600, 600)], true)
        ->call('submit')
        ->assertHasNoErrors();

    $request = ContributionRequest::query()
        ->where('entity_id', $institution->getKey())
        ->latest('id')
        ->firstOrFail();

    expect($request->getMedia('pending_media'))->toHaveCount(1);

    app(RejectContributionRequestAction::class)->handle($request->fresh(), $reviewer, 'incorrect_information');

    expect($request->fresh()->getMedia('pending_media'))->toHaveCount(0);
});

it('runs the institution update submit under the Maps identity lock', function () {
    expect(config('moderation.review_lock_block_seconds'))->toBe(10);

    config()->set('moderation.review_lock_block_seconds', 1);

    $owner = User::factory()->create();
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'name' => 'Masjid Lama',
    ]);
    giveInstitutionStoredMapsPlace($institution);
    withGlobalOwnerContext(fn (): null => addTestMember($institution, $owner, MemberRole::Owner));

    // Hold the identity lock up front: a submit that truly runs under the
    // lock must block on it instead of sailing through.
    $heldLock = Cache::lock(
        'contribution-create:institution-maps:'.hash('sha256', 'place:place_stored_existing'),
        300,
    );
    $heldLock->acquire();

    $busyMessage = __('The review service is busy. Please try again in a moment.');

    try {
        Livewire::actingAs($owner)
            ->test(SuggestUpdate::class, [
                'subjectType' => ContributionSubjectType::Institution->publicRouteSegment(),
                'subjectId' => $institution->slug,
            ])
            ->set('data.name', 'Masjid Baharu')
            ->call('submit')
            ->assertHasErrors(['data.address.google_place_id' => fn (array $rules, array $messages): bool => in_array($busyMessage, $messages, true)]);
    } catch (LockTimeoutException) {
        $this->fail('Expected the submit to surface a busy validation error instead of the raw lock timeout.');
    } finally {
        $heldLock->release();
    }

    expect($institution->fresh()->name)->toBe('Masjid Lama');
});

it('shows a busy validation error when the Maps identity lock is held during institution submit', function () {
    $country = ensureCountryForLocationPicker('MY', 'Malaysia');
    $state = ensureMalaysiaStateForLocationPicker();
    $district = createTestAddressArea('Petaling', 2, parent: $state['area'], country: $country, type: 'district');
    createTestAddressArea('Shah Alam', 3, parent: $district, country: $country, type: 'subdistrict');

    configureVerifiedGoogleMaps();
    fakeVerifiedInstitutionPlace(verifiedInstitutionPlace('busy_create_place'));
    config()->set('moderation.review_lock_block_seconds', 1);

    $busyMessage = __('The review service is busy. Please try again in a moment.');

    // The fixture carries google_cid 11885663895765773631, so the identity
    // lock key is cid-based.
    $heldLock = Cache::lock(
        'contribution-create:institution-maps:'.hash('sha256', 'cid:11885663895765773631'),
        300,
    );
    expect($heldLock->acquire())->toBeTrue();

    try {
        Livewire::actingAs(User::factory()->create())
            ->test(SubmitInstitution::class)
            ->call('applyPlaceSelection', 'data.address', ['placeId' => 'busy_create_place'])
            ->assertHasNoErrors()
            ->set('data.name', 'Busy Submit Institution')
            ->set('data.type', 'masjid')
            ->call('submit')
            ->assertHasErrors(['data.address.google_place_id' => fn (array $rules, array $messages): bool => in_array($busyMessage, $messages, true)]);
    } finally {
        $heldLock->release();
    }

    expect(Institution::query()->where('name', 'Busy Submit Institution')->exists())->toBeFalse();
});
