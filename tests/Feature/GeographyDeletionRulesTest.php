<?php

use App\Actions\Location\GetGeographyDeletionBlockReasonAction;
use App\Models\Country;
use App\Models\District;
use App\Models\Institution;
use App\Models\State;
use App\Models\Subdistrict;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createTestCountry(array $overrides = []): Country
{
    return Country::query()->create(array_merge([
        'name' => 'Testland',
        'iso2' => 'TL',
        'iso3' => 'TST',
        'status' => 1,
        'phone_code' => '999',
        'region' => 'Test Region',
        'subregion' => 'Test Subregion',
    ], $overrides));
}

it('blocks deleting the malaysia country record', function () {
    $country = createTestCountry([
        'id' => 132,
        'name' => 'Malaysia',
        'iso2' => 'MY',
        'iso3' => 'MYS',
        'phone_code' => '60',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
    ]);

    $reason = app(GetGeographyDeletionBlockReasonAction::class)->handle($country);

    expect($reason)->toBe('Malaysia (ID 132) is the application default country and cannot be deleted.');
});

it('blocks deleting a state that still has districts', function () {
    $country = createTestCountry();

    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Central State',
        'country_code' => 'TL',
    ]);

    District::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Main District',
        'country_code' => 'TL',
    ]);

    $reason = app(GetGeographyDeletionBlockReasonAction::class)->handle($state);

    expect($reason)->toBe('Delete or reassign this state\'s districts before deleting it.');
});

it('blocks deleting a district that still has subdistricts', function () {
    $country = createTestCountry();

    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Central State',
        'country_code' => 'TL',
    ]);

    $district = District::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Main District',
        'country_code' => 'TL',
    ]);

    Subdistrict::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'district_id' => $district->getKey(),
        'name' => 'Mukim One',
        'country_code' => 'TL',
    ]);

    $reason = app(GetGeographyDeletionBlockReasonAction::class)->handle($district);

    expect($reason)->toBe('Delete or reassign this district\'s subdistricts before deleting it.');
});

it('blocks deleting a subdistrict that is still referenced by an address', function () {
    $country = createTestCountry();

    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Central State',
        'country_code' => 'TL',
    ]);

    $district = District::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Main District',
        'country_code' => 'TL',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'district_id' => $district->getKey(),
        'name' => 'Mukim One',
        'country_code' => 'TL',
    ]);

    $institution = Institution::factory()->create();

    $institution->address()->update([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'district_id' => $district->getKey(),
        'subdistrict_id' => $subdistrict->getKey(),
    ]);

    $reason = app(GetGeographyDeletionBlockReasonAction::class)->handle($subdistrict);

    expect($reason)->toBe('This subdistrict is still referenced by one or more addresses.');
});

it('allows deleting an unused subdistrict', function () {
    $country = createTestCountry();

    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Central State',
        'country_code' => 'TL',
    ]);

    $district = District::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Main District',
        'country_code' => 'TL',
    ]);

    $subdistrict = Subdistrict::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'district_id' => $district->getKey(),
        'name' => 'Mukim One',
        'country_code' => 'TL',
    ]);

    $reason = app(GetGeographyDeletionBlockReasonAction::class)->handle($subdistrict);

    expect($reason)->toBeNull();
});
