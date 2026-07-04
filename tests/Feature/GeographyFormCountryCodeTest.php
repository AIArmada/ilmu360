<?php

use AIArmada\FilamentAddressing\Resources\AddressAreaResource;
use App\Actions\AddressAreas\SaveAddressAreaAction;
use App\Support\Api\Admin\AdminResourceMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not expose manual country code editing in the package-native address area schema', function () {
    $schema = app(AdminResourceMutationService::class)->schema(
        AddressAreaResource::class,
        'address-areas',
    );

    $fieldNames = collect($schema['fields'] ?? [])->pluck('name');

    expect($fieldNames)
        ->toContain('country_id')
        ->toContain('parent_id')
        ->toContain('type')
        ->toContain('level')
        ->toContain('name')
        ->not->toContain('country_code');
});

it('derives geography country codes from the selected country on save', function () {
    $country = ensureTestAddressCountry('TL', 'Testland', 'TST', ['UTC'], '999');
    $saveAddressArea = app(SaveAddressAreaAction::class);

    $state = $saveAddressArea->handle([
        'country_id' => (string) $country->getKey(),
        'type' => 'state',
        'name' => 'Alpha State',
    ]);

    expect($state->country_code)->toBe('TL');

    $district = $saveAddressArea->handle([
        'country_id' => (string) $country->getKey(),
        'parent_id' => (string) $state->getKey(),
        'type' => 'district',
        'name' => 'Alpha District',
    ]);

    expect($district->country_code)->toBe('TL');

    $subdistrict = $saveAddressArea->handle([
        'country_id' => (string) $country->getKey(),
        'parent_id' => (string) $district->getKey(),
        'type' => 'subdistrict',
        'level' => 3,
        'name' => 'Alpha Subdistrict',
    ]);

    expect($subdistrict->country_code)->toBe('TL');
});

it('allows creating federal territory subdistricts without a district', function () {
    $country = ensureTestMalaysiaCountry();
    $state = createTestAddressArea('Kuala Lumpur', 1, country: $country);

    $subdistrict = app(SaveAddressAreaAction::class)->handle([
        'country_id' => (string) $country->getKey(),
        'parent_id' => (string) $state->getKey(),
        'type' => 'subdistrict',
        'level' => 3,
        'name' => 'Setiawangsa',
    ]);

    expect($subdistrict->parent_id)->toBe((string) $state->getKey())
        ->and($subdistrict->country_code)->toBe('MY');
});
