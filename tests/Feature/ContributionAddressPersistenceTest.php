<?php

use AIArmada\Addressing\Models\AddressAreaAssignment;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Contributions\ApplyDirectContributionUpdateAction;
use App\Actions\Contributions\SubmitStagedContributionCreateAction;
use App\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Models\ContributionRequest;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

it('persists state city and area assignments when creating institutions through the frontend api', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $geo = createTestPackageGeography(subdistrictName: 'Damansara', cityName: 'Petaling Jaya');

    $this->postJson(route('api.client.contributions.institutions.store'), [
        'type' => 'masjid',
        'name' => 'Masjid Geo Penuh',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'city_id' => (string) $geo['city']->getKey(),
            'line1' => 'Jalan Geo',
            'google_maps_url' => 'https://maps.google.com/?q=masjid+geo',
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ])->assertCreated();

    $address = Institution::query()->where('name', 'Masjid Geo Penuh')->firstOrFail()->primaryAddress();

    expect($address->state_id)->toBe((string) $geo['state']->getKey())
        ->and($address->city_id)->toBe((string) $geo['city']->getKey())
        ->and(AddressAreaAssignment::query()->where('address_id', $address->getKey())->where('role', 'administrative_district')->where('address_area_id', (string) $geo['district']->getKey())->exists())->toBeTrue();
});

it('preserves area assignments when direct updates omit them', function () {
    $user = User::factory()->create();
    $geo = createTestPackageGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Kekal Daerah',
        'type' => 'masjid',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'line1' => 'Jalan Asal',
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ], $user);

    $addressId = (string) $institution->primaryAddress()->getKey();

    expect(AddressAreaAssignment::query()->where('address_id', $addressId)->count())->toBe(1);

    app(ApplyDirectContributionUpdateAction::class)->handle($institution, [
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'line1' => 'Jalan Baharu',
        ],
    ]);

    expect(AddressAreaAssignment::query()->where('address_id', $addressId)->where('role', 'administrative_district')->exists())->toBeTrue()
        ->and($institution->fresh()->primaryAddress()->line1)->toBe('Jalan Baharu');
});

it('clears area assignments when direct updates explicitly empty them', function () {
    $user = User::factory()->create();
    $geo = createTestPackageGeography();

    $institution = app(ContributionEntityMutationService::class)->createInstitution([
        'name' => 'Masjid Padam Daerah',
        'type' => 'masjid',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'line1' => 'Jalan Asal',
            'area_assignments' => [
                'administrative_district' => (string) $geo['district']->getKey(),
            ],
        ],
    ], $user);

    $addressId = (string) $institution->primaryAddress()->getKey();

    app(ApplyDirectContributionUpdateAction::class)->handle($institution, [
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'line1' => 'Jalan Kekal',
            'area_assignments' => [],
        ],
    ]);

    expect(AddressAreaAssignment::query()->where('address_id', $addressId)->exists())->toBeFalse()
        ->and($institution->fresh()->primaryAddress()->line1)->toBe('Jalan Kekal');
});

it('rolls back staged creates when area sync fails', function () {
    $user = User::factory()->create();
    $geo = createTestPackageGeography();

    try {
        app(SubmitStagedContributionCreateAction::class)->handle(
            ContributionSubjectType::Institution,
            [
                'name' => 'Masjid Gagal Tulis',
                'type' => 'masjid',
                'address' => [
                    'country_id' => (string) $geo['country']->getKey(),
                    'state_id' => (string) $geo['state']->getKey(),
                    'line1' => 'Jalan Gagal',
                    'area_assignments' => [
                        'bogus_role' => (string) $geo['district']->getKey(),
                    ],
                ],
            ],
            $user,
        );

        $this->fail('Expected the unknown area role to fail area sync.');
    } catch (ValidationException) {
        // Expected: the bogus role is rejected after the entity and address rows are written.
    }

    expect(Institution::query()->where('name', 'Masjid Gagal Tulis')->exists())->toBeFalse()
        ->and(ContributionRequest::query()->count())->toBe(0);
});

it('rejects unknown area roles at the api boundary', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $geo = createTestPackageGeography();

    $this->postJson(route('api.client.contributions.institutions.store'), [
        'type' => 'masjid',
        'name' => 'Masjid Peranan Palsu',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
            'line1' => 'Jalan Palsu',
            'google_maps_url' => 'https://maps.google.com/?q=masjid+palsu',
            'area_assignments' => [
                'bogus_role' => (string) $geo['district']->getKey(),
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['address.area_assignments']);

    expect(Institution::query()->where('name', 'Masjid Peranan Palsu')->exists())->toBeFalse();
});

it('rejects areas from another country naming the offending role', function () {
    $geo = createTestPackageGeography();
    $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');
    $singaporeDistrict = createTestAddressArea('West Region', 2, country: $singapore, type: 'district');

    try {
        SharedFormSchema::prepareAddressPersistenceData([
            'country_id' => (string) $geo['country']->getKey(),
            'area_assignments' => [
                'administrative_district' => (string) $singaporeDistrict->getKey(),
            ],
        ]);

        $this->fail('Expected the wrong-country area to be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('address.area_assignments.administrative_district');
    }
});

it('keeps typed city text when areas are also selected', function () {
    $geo = createTestPackageGeography();

    $payload = SharedFormSchema::prepareAddressPersistenceData([
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'city' => 'Typed Town',
        'area_assignments' => [
            'administrative_district' => (string) $geo['district']->getKey(),
        ],
    ]);

    expect($payload['city'])->toBe('Typed Town');
});

it('rejects state and city keys on the region-only person api', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $geo = createTestPackageGeography();

    $this->postJson(route('api.client.contributions.persons.store'), [
        'name' => 'Penceramah Negeri',
        'gender' => 'male',
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['address.state_id']);

    expect(Person::query()->where('name', 'Penceramah Negeri')->exists())->toBeFalse();
});

it('rejects state keys on person suggest updates', function () {
    $owner = User::factory()->create();
    $geo = createTestPackageGeography();

    $person = Person::factory()->create(['status' => 'verified']);
    addTestMember($person, $owner, MemberRole::Owner);

    syncPrimaryAddressForTest($person, [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
    ]);

    Sanctum::actingAs($owner);

    $this->postJson(route('api.client.contributions.suggest.store', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subject' => $person->slug,
    ]), [
        'address' => [
            'country_id' => (string) $geo['country']->getKey(),
            'state_id' => (string) $geo['state']->getKey(),
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['address.state_id']);
});
