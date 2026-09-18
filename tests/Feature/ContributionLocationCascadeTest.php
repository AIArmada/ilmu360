<?php

use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\State;
use App\Livewire\Pages\Contributions\SubmitInstitution;
use App\Models\User;
use Livewire\Livewire;

function submitInstitutionAddressFields($component): array
{
    return collect($component->instance()->getForm('contributionForm')->getFlatFields(withHidden: true))
        ->keyBy(fn (mixed $field): string => $field->getName())
        ->all();
}

it('builds Indonesian area fields on the institution contribution form', function (): void {
    app()->setLocale('ms');

    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');
    $indonesiaId = (string) $indonesia->getKey();

    $province = State::query()->firstOrCreate(
        ['country_id' => $indonesiaId, 'name' => 'Jawa Barat'],
        ['code' => null],
    );
    $provinceArea = createTestAddressArea('Jawa Barat', 1, country: $indonesia, type: 'province');
    AddressAreaStateLink::query()->firstOrCreate(
        ['address_area_id' => $provinceArea->getKey(), 'state_id' => $province->getKey()],
        ['hierarchy_type' => 'administrative'],
    );
    createTestAddressArea('Bandung', 2, parent: $provinceArea, country: $indonesia, type: 'regency');

    $component = Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->set('data.address.country_id', $indonesiaId)
        ->set('data.address.state_id', (string) $province->getKey());

    $fields = submitInstitutionAddressFields($component);

    expect($fields)->toHaveKey('area_assignments.regency')
        ->and($fields)->toHaveKey('area_assignments.district')
        ->and($fields['area_assignments.regency']->getLabel())->toBe('Kabupaten / Kota')
        ->and($fields['area_assignments.regency']->isVisible())->toBeTrue()
        ->and($fields['state_id']->isVisible())->toBeTrue();
});

it('hides the state select for stateless Singapore on the contribution form', function (): void {
    app()->setLocale('ms');

    $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');
    $singaporeId = (string) $singapore->getKey();

    State::query()->firstOrCreate(
        ['country_id' => $singaporeId, 'name' => 'North West'],
        ['code' => '03'],
    );

    $central = createTestAddressArea('Central', 1, country: $singapore, type: 'region');
    createTestAddressArea('Bishan', 2, parent: $central, country: $singapore, type: 'planning_area');

    $component = Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->set('data.address.country_id', $singaporeId);

    $fields = submitInstitutionAddressFields($component);

    expect($fields)->toHaveKey('area_assignments.region')
        ->and($fields)->toHaveKey('area_assignments.planning_area')
        ->and($fields['area_assignments.region']->getLabel())->toBe('Wilayah Perancangan')
        ->and($fields['area_assignments.region']->isVisible())->toBeTrue()
        ->and($fields['state_id']->isHidden())->toBeTrue()
        ->and($fields['state']->isVisible())->toBeTrue();
});

it('keeps the Malaysian entry fields unchanged', function (): void {
    app()->setLocale('ms');

    $geo = createTestPackageGeography('Selangor', 'Petaling', 'Subang');

    $component = Livewire::actingAs(User::factory()->create())
        ->test(SubmitInstitution::class)
        ->set('data.address.country_id', (string) $geo['country']->getKey())
        ->set('data.address.state_id', (string) $geo['state']->getKey());

    $fields = submitInstitutionAddressFields($component);

    expect($fields)->toHaveKey('area_assignments.administrative_district')
        ->and($fields)->toHaveKey('area_assignments.administrative_subdivision')
        ->and($fields)->toHaveKey('area_assignments.postal_locality')
        ->and($fields['area_assignments.administrative_district']->getLabel())->toBe('Daerah / Jajahan')
        ->and($fields['area_assignments.administrative_district']->isVisible())->toBeTrue()
        ->and($fields['state_id']->isVisible())->toBeTrue();
});
