<?php

use AIArmada\Addressing\Models\State;
use AIArmada\FilamentAddressing\RelationManagers\StatesRelationManager;
use AIArmada\FilamentAddressing\Resources\AddressCountryResource;

it('exposes the states relation on country records', function (): void {
    $country = ensureTestMalaysiaCountry();

    $selangor = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Selangor Test',
        'country_code' => 'MY',
        'code' => 'SLT',
    ]);

    State::query()->create([
        'country_id' => ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65')->getKey(),
        'name' => 'Singapore Test',
        'country_code' => 'SG',
        'code' => 'SGT',
    ]);

    expect($country->states()->pluck('id')->all())
        ->toBe([$selangor->getKey()]);
});

it('shows states on the country resource detail page', function (): void {
    expect(AddressCountryResource::getRelations())
        ->toContain(StatesRelationManager::class);
});
