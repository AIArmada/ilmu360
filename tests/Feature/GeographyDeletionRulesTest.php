<?php

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows deleting an unused country record', function () {
    $country = ensureTestMalaysiaCountry();

    $country->delete();

    $this->assertModelMissing($country);
});

it('allows deleting a state and orphans its child districts', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $state = createTestAddressArea('Central State', 1, country: $country);
    $district = createTestAddressArea('Main District', 2, parent: $state, country: $country);

    $state->delete();

    $this->assertModelMissing($state);
    expect($district->fresh()?->parent_id)->toBeNull();
});

it('allows deleting a district and orphans its child subdistricts', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $state = createTestAddressArea('Central State', 1, country: $country);
    $district = createTestAddressArea('Main District', 2, parent: $state, country: $country);
    $subdistrict = createTestAddressArea('Mukim One', 3, parent: $district, country: $country);

    $district->delete();

    $this->assertModelMissing($district);
    expect($subdistrict->fresh()?->parent_id)->toBeNull();
});

it('allows deleting a subdistrict that is still referenced by an address and cascades cleanup', function () {
    $country = ensureTestMalaysiaCountry();
    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Shah Alam', country: $country);

    $institution = Institution::factory()->create();

    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $country->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'administrative_district_id' => (string) $geo['district']->getKey(),
        'administrative_subdivision_id' => (string) $geo['subdistrict']->getKey(),
    ]);

    $subdistrict = $geo['subdistrict'];
    $subdistrict->delete();

    $this->assertModelMissing($subdistrict);
});

it('allows deleting an unused subdistrict', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $state = createTestAddressArea('Central State', 1, country: $country);
    $district = createTestAddressArea('Main District', 2, parent: $state, country: $country);
    $subdistrict = createTestAddressArea('Mukim One', 3, parent: $district, country: $country);

    $subdistrict->delete();

    $this->assertModelMissing($subdistrict);
});
