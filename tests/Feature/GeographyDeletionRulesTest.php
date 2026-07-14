<?php

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('allows deleting an unused country record', function () {
    $country = ensureTestMalaysiaCountry();

    $country->delete();

    $this->assertModelMissing($country);
});

it('blocks deleting a state that still has districts', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $state = createTestAddressArea('Central State', 1, country: $country);
    createTestAddressArea('Main District', 2, parent: $state, country: $country);

    expect(fn () => $state->delete())
        ->toThrow(ValidationException::class, 'Delete or reassign this address area\'s child areas before deleting it.');
});

it('blocks deleting a district that still has subdistricts', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $state = createTestAddressArea('Central State', 1, country: $country);
    $district = createTestAddressArea('Main District', 2, parent: $state, country: $country);
    createTestAddressArea('Mukim One', 3, parent: $district, country: $country);

    expect(fn () => $district->delete())
        ->toThrow(ValidationException::class, 'Delete or reassign this address area\'s child areas before deleting it.');
});

it('blocks deleting a subdistrict that is still referenced by an address', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $state = createTestAddressArea('Central State', 1, country: $country);
    $district = createTestAddressArea('Main District', 2, parent: $state, country: $country);
    $subdistrict = createTestAddressArea('Mukim One', 3, parent: $district, country: $country);

    $institution = Institution::factory()->create();

    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $country->getKey(),
        'state_id' => (string) $state->getKey(),
        'admin_area_1_id' => (string) $district->getKey(),
        'admin_area_2_id' => (string) $subdistrict->getKey(),
    ]);

    expect(fn () => $subdistrict->delete())
        ->toThrow(ValidationException::class, 'This address area is still referenced by one or more addresses.');
});

it('allows deleting an unused subdistrict', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $state = createTestAddressArea('Central State', 1, country: $country);
    $district = createTestAddressArea('Main District', 2, parent: $state, country: $country);
    $subdistrict = createTestAddressArea('Mukim One', 3, parent: $district, country: $country);

    $subdistrict->delete();

    $this->assertModelMissing($subdistrict);
});
