<?php

use AIArmada\Signals\Models\SignalEvent;
use App\Enums\InstitutionStatus;
use App\Forms\SharedFormSchema;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function configureEventInstitutionQuickAddMaps(): void
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
function eventInstitutionQuickAddPlace(string $placeId, array $overrides = []): array
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
        'websiteUri' => null,
        'internationalPhoneNumber' => '+60 3-1234 5678',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $place
 */
function fakeEventInstitutionQuickAddPlace(array $place, bool $withHomepage = false): void
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

    Http::preventStrayRequests();
    Http::fake($responses);
}

/**
 * @return array{component: Testable, nonce: string, formPath: string, addressPath: string, ownerPath: string}
 */
function mountEventInstitutionQuickAdd(string $field): array
{
    ensureTestMalaysiaCountry();

    $ownerPath = 'data.'.$field;
    $component = Livewire::actingAs(User::factory()->create())
        ->test(Create::class)
        ->mountFormComponentAction($field, 'createOption');
    $nonce = $component->get('mountedActions.0.data.address.maps_selection_nonce');

    return [
        'component' => $component,
        'nonce' => is_string($nonce) ? $nonce : '',
        'formPath' => 'mountedActions.0.data',
        'addressPath' => 'mountedActions.0.data.address',
        'ownerPath' => $ownerPath,
    ];
}

it('mounts both event institution quick-add actions and rotates each modal nonce', function () {
    ensureTestMalaysiaCountry();

    $organizer = mountEventInstitutionQuickAdd('primary_organizer_institution_id');

    expect($organizer['nonce'])->not->toBe('');
    $organizer['component']->unmountFormComponentAction();

    $reopened = $organizer['component']->mountFormComponentAction('primary_organizer_institution_id', 'createOption');
    $reopenedNonce = $reopened->get('mountedActions.0.data.address.maps_selection_nonce');

    expect($reopenedNonce)->toBeString()->not->toBe($organizer['nonce']);

    $location = mountEventInstitutionQuickAdd('location_institution_id');

    expect($location['nonce'])->toBeString()->not->toBe('');
    $location['component']->assertFormComponentActionMounted('location_institution_id', 'createOption');
});

it('rejects an unverified mounted create and a tampered selection token', function () {
    configureEventInstitutionQuickAddMaps();
    $geography = createTestPackageGeography();
    Http::preventStrayRequests();
    Http::fake();

    $missingProof = mountEventInstitutionQuickAdd('primary_organizer_institution_id')['component']
        ->set('mountedActions.0.data.name', 'Unverified Quick Add')
        ->set('mountedActions.0.data.type', 'masjid')
        ->set('mountedActions.0.data.address.country_id', (string) $geography['country']->getKey())
        ->callMountedFormComponentAction()
        ->assertHasFormErrors(['address.google_place_id']);

    expect(Institution::query()->where('name', 'Unverified Quick Add')->exists())->toBeFalse();

    fakeEventInstitutionQuickAddPlace(eventInstitutionQuickAddPlace('tampered_quick_add_place'));

    $opened = mountEventInstitutionQuickAdd('primary_organizer_institution_id');
    $component = $opened['component']
        ->set('mountedActions.0.data.name', 'Tampered Quick Add')
        ->set('mountedActions.0.data.type', 'masjid')
        ->call('applyQuickAddInstitutionPlaceSelection', $opened['addressPath'], [
            'placeId' => 'tampered_quick_add_place',
            'ownerStatePath' => $opened['ownerPath'],
            'nonce' => $opened['nonce'],
        ])
        ->set('mountedActions.0.data.address.maps_selection_token', 'tampered-token')
        ->callMountedFormComponentAction()
        ->assertHasFormErrors(['address.google_place_id']);

    expect(Institution::query()->where('name', 'Tampered Quick Add')->exists())->toBeFalse();
    Http::assertSentCount(1);
});

it('rejects stale and cross-field place selections before looking up Google', function () {
    configureEventInstitutionQuickAddMaps();
    fakeEventInstitutionQuickAddPlace(eventInstitutionQuickAddPlace('fresh_reopened_place'));

    $opened = mountEventInstitutionQuickAdd('primary_organizer_institution_id');
    $opened['component']->call('applyQuickAddInstitutionPlaceSelection', $opened['addressPath'], [
        'placeId' => 'cross_field_place',
        'ownerStatePath' => 'data.location_institution_id',
        'nonce' => $opened['nonce'],
    ])->assertHasErrors(['mountedActions.0.data.address.google_place_id']);

    $opened['component']->unmountFormComponentAction();
    $reopened = $opened['component']->mountFormComponentAction('primary_organizer_institution_id', 'createOption');
    $newNonce = $reopened->get('mountedActions.0.data.address.maps_selection_nonce');

    expect($newNonce)->toBeString()->not->toBe($opened['nonce']);

    $reopened->call('applyQuickAddInstitutionPlaceSelection', $opened['addressPath'], [
        'placeId' => 'stale_place',
        'ownerStatePath' => $opened['ownerPath'],
        'nonce' => $opened['nonce'],
    ])->assertHasErrors(['mountedActions.0.data.address.google_place_id']);

    $reopened
        ->set('mountedActions.0.data.name', 'Expired Selection')
        ->set('mountedActions.0.data.type', 'masjid')
        ->call('applyQuickAddInstitutionPlaceSelection', $opened['addressPath'], [
            'placeId' => 'fresh_reopened_place',
            'ownerStatePath' => $opened['ownerPath'],
            'nonce' => $newNonce,
        ])
        ->assertHasNoErrors();

    $token = $reopened->get('institutionQuickAddProofToken');
    Cache::forget('event-institution-quick-add:verified-maps:'.hash('sha256', session()->getId().'|'.$reopened->id().'|'.$token));

    $reopened->callMountedFormComponentAction()
        ->assertHasFormErrors(['address.google_place_id']);

    expect(Institution::query()->where('name', 'Expired Selection')->exists())->toBeFalse();
    Http::assertSentCount(1);
});

it('creates a verified organizer institution with provider geography and editable contact corrections', function () {
    $geography = createTestPackageGeography('Selangor', 'Petaling', 'Seksyen 14', 'Shah Alam');
    configureEventInstitutionQuickAddMaps();
    fakeEventInstitutionQuickAddPlace(eventInstitutionQuickAddPlace('organizer_place', [
        'websiteUri' => 'https://93.184.216.34/',
    ]), withHomepage: true);

    $user = User::factory()->create();
    $mounted = Livewire::actingAs($user)
        ->test(Create::class)
        ->mountFormComponentAction('primary_organizer_institution_id', 'createOption');
    $nonce = $mounted->get('mountedActions.0.data.address.maps_selection_nonce');

    expect($nonce)->toBeString()->not->toBe('');

    $component = $mounted
        ->set('mountedActions.0.data.name', 'Masjid Jamik Corrected Name')
        ->set('mountedActions.0.data.type', 'pondok')
        ->call('applyQuickAddInstitutionPlaceSelection', 'mountedActions.0.data.address', [
            'placeId' => 'organizer_place',
            'ownerStatePath' => 'data.primary_organizer_institution_id',
            'nonce' => $nonce,
        ])
        ->assertHasNoErrors()
        ->assertSet('mountedActions.0.data.name', 'Masjid Jamik Corrected Name')
        ->assertSet('mountedActions.0.data.type', 'pondok')
        ->assertSet('mountedActions.0.data.address.country_id', (string) $geography['country']->getKey())
        ->assertSet('mountedActions.0.data.address.state_id', (string) $geography['state']->getKey())
        ->assertSet('mountedActions.0.data.address.city_id', (string) $geography['city']->getKey())
        ->set('mountedActions.0.data.address.line1', 'Manual Road Name')
        ->set('mountedActions.0.data.address.postcode', '40001')
        ->set('mountedActions.0.data.contactMethods.0.value', 'https://manual.example.test/')
        ->set('mountedActions.0.data.social_media.0.handle', 'manual-page')
        ->callMountedFormComponentAction()
        ->assertHasNoFormErrors();

    $institution = Institution::query()->where('name', 'Masjid Jamik Corrected Name')->firstOrFail();
    $address = $institution->primaryAddress();

    expect($institution->status)->toBe(InstitutionStatus::Pending)
        ->and($institution->type->value)->toBe('pondok')
        ->and($address?->country_id)->toBe((string) $geography['country']->getKey())
        ->and($address?->state_id)->toBe((string) $geography['state']->getKey())
        ->and($address?->city_id)->toBe((string) $geography['city']->getKey())
        ->and($address?->google_place_id)->toBe('organizer_place')
        ->and($address?->google_cid)->toBe('11885663895765773631')
        ->and($address?->line1)->toBe('Manual Road Name')
        ->and($address?->postcode)->toBe('40001')
        ->and($address?->latitude)->toBe(3.07853)
        ->and($address?->longitude)->toBe(101.52073)
        ->and($institution->contactMethods()->where('type', 'website')->value('value'))->toBe('https://manual.example.test/')
        ->and($institution->contactMethods()->where('type', 'phone')->value('value'))->toBe('+60312345678')
        ->and($institution->socialProfiles()->where('platform', 'facebook')->value('handle'))->toBe('manual-page')
        ->and($institution->socialProfiles()->where('platform', 'instagram')->value('handle'))->toBe('ilmu360')
        ->and($component->get('data.primary_organizer_institution_id'))->toBe((string) $institution->getKey())
        ->and($component->get('institutionQuickAddProofToken'))->toBeNull();

    expect(SignalEvent::query()->where('event_name', 'contribution.institution_maps_place_selected')->exists())->toBeTrue();
});

it('blocks a duplicate Maps identity when quick-adding a location institution', function () {
    $geography = createTestPackageGeography();
    $existing = Institution::factory()->create(['status' => InstitutionStatus::Verified]);
    SharedFormSchema::createAddressFromData($existing, [
        'country_id' => (string) $geography['country']->getKey(),
        'google_place_id' => 'existing_place',
        'google_cid' => '11885663895765773631',
        'google_maps_url' => 'https://maps.google.com/?cid=11885663895765773631',
    ], allowCountryOnly: true);

    configureEventInstitutionQuickAddMaps();
    fakeEventInstitutionQuickAddPlace(eventInstitutionQuickAddPlace('new_location_place'));

    $opened = mountEventInstitutionQuickAdd('location_institution_id');
    $component = $opened['component']
        ->set('mountedActions.0.data.name', 'Duplicate location institution')
        ->set('mountedActions.0.data.type', 'masjid')
        ->set('mountedActions.0.data.address.country_id', (string) $geography['country']->getKey())
        ->call('applyQuickAddInstitutionPlaceSelection', $opened['addressPath'], [
            'placeId' => 'new_location_place',
            'ownerStatePath' => $opened['ownerPath'],
            'nonce' => $opened['nonce'],
        ])
        ->callMountedFormComponentAction()
        ->assertHasFormErrors(['name']);

    expect(Institution::query()->count())->toBe(1);
});

it('fetches attributed photo previews from fresh Place details only while the quick-add proof is active', function () {
    configureEventInstitutionQuickAddMaps();
    Http::preventStrayRequests();
    Http::fake([
        'https://places.googleapis.com/v1/places/quick_add_photo_place/photos/*/media*' => Http::response([
            'photoUri' => 'https://lh3.googleusercontent.com/photo-one=s800',
        ], 200),
        'https://places.googleapis.com/v1/places/*' => Http::response(eventInstitutionQuickAddPlace('quick_add_photo_place', [
            'photos' => [[
                'name' => 'places/quick_add_photo_place/photos/photo-one',
                'widthPx' => 800,
                'heightPx' => 600,
                'googleMapsUri' => 'https://maps.google.com/?cid=123',
                'authorAttributions' => [['displayName' => 'Photo contributor']],
            ]],
        ]), 200),
    ]);

    $opened = mountEventInstitutionQuickAdd('primary_organizer_institution_id');
    $component = $opened['component']->call('applyQuickAddInstitutionPlaceSelection', $opened['addressPath'], [
        'placeId' => 'quick_add_photo_place',
        'ownerStatePath' => $opened['ownerPath'],
        'nonce' => $opened['nonce'],
    ]);

    $component->call('loadQuickAddInstitutionPhotoPreviews', 'another_place', $opened['addressPath'], $opened['ownerPath'])
        ->assertReturned([]);

    $component->call('loadQuickAddInstitutionPhotoPreviews', 'quick_add_photo_place', $opened['addressPath'], $opened['ownerPath'])
        ->assertReturned([[
            'preview_url' => 'https://lh3.googleusercontent.com/photo-one=s800',
            'width' => 800,
            'height' => 600,
            'attributions' => [['display_name' => 'Photo contributor', 'uri' => null]],
            'google_maps_uri' => 'https://maps.google.com/?cid=123',
        ]]);

    Http::assertSentCount(3);
    Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Goog-Api-Key', 'test-server-key'));
});
