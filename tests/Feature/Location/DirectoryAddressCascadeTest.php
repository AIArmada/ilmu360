<?php

declare(strict_types=1);

use App\Livewire\Pages\Events\Index;
use App\Support\Location\VisitorCountryResolver;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(VisitorCountryResolver::class)->forget();
});

function cascadeSelectField($component, string $name): Select
{
    $field = collect($component->instance()->getForm('form')->getFlatFields())
        ->first(fn (mixed $field): bool => $field instanceof Select && $field->getName() === $name);

    expect($field)->toBeInstanceOf(Select::class);

    return $field;
}

it('reveals the provider address levels once a state is chosen', function (): void {
    $country = ensureTestMalaysiaCountry();
    $geography = createTestPackageGeography('Selangor', 'Petaling', 'Petaling Jaya', country: $country);

    // Choosing a state exposes the district level for a provider-backed country.
    Livewire::test(Index::class)
        ->set('filterData.country_id', (string) $country->getKey())
        ->set('filterData.state_id', (string) $geography['state']->getKey())
        ->assertSee(__('Daerah'));

    // The subdivision level only reveals once a district is chosen — that
    // completes the Malaysia provider cascade. The fixture subdivision is
    // subdistrict-typed, so the precise label reads Daerah Kecil.
    Livewire::test(Index::class)
        ->set('filterData.country_id', (string) $country->getKey())
        ->set('filterData.state_id', (string) $geography['state']->getKey())
        ->set('filterData.area_assignments.administrative_district', (string) $geography['district']->getKey())
        ->assertSee('Daerah Kecil')
        ->assertDontSee('Bandar / Mukim / Zon');
});

it('keeps the provider address levels collapsed for a country without a geography provider', function (): void {
    $malaysia = ensureTestMalaysiaCountry();
    createTestPackageGeography('Selangor', 'Petaling', 'Petaling Jaya', country: $malaysia);

    $singapore = ensureTestAddressCountry('SG', 'Singapore');

    // Only Malaysia registers a Geography provider, so Singapore exposes no
    // district / subdivision levels to expand into.
    Livewire::test(Index::class)
        ->set('filterData.country_id', (string) $singapore->getKey())
        ->assertDontSee(__('Daerah'))
        ->assertDontSee(__('Bandar / Mukim / Zon'));
});

it('cascades the district options from the selected state', function (): void {
    $country = ensureTestMalaysiaCountry();
    $selangor = createTestPackageGeography('Selangor', 'Petaling', 'Petaling Jaya', country: $country);
    $johor = createTestPackageGeography('Johor', 'Johor Bahru', 'Tebrau', country: $country);

    $component = Livewire::test(Index::class)
        ->set('filterData.country_id', (string) $country->getKey())
        ->set('filterData.state_id', (string) $selangor['state']->getKey());

    $districtNames = array_values(cascadeSelectField($component, 'area_assignments.administrative_district')->getSearchResults(''));

    expect($districtNames)->toContain('Petaling')
        ->and($districtNames)->not->toContain('Johor Bahru');

    $component->set('filterData.state_id', (string) $johor['state']->getKey());

    $districtNames = array_values(cascadeSelectField($component, 'area_assignments.administrative_district')->getSearchResults(''));

    expect($districtNames)->toContain('Johor Bahru')
        ->and($districtNames)->not->toContain('Petaling');
});

it('populates the subdivision options from the selected district', function (): void {
    $country = ensureTestMalaysiaCountry();
    $geography = createTestPackageGeography('Selangor', 'Petaling', 'Petaling Jaya', country: $country);

    $component = Livewire::test(Index::class)
        ->set('filterData.country_id', (string) $country->getKey())
        ->set('filterData.state_id', (string) $geography['state']->getKey())
        ->set('filterData.area_assignments.administrative_district', (string) $geography['district']->getKey());

    expect(array_values(cascadeSelectField($component, 'area_assignments.administrative_subdivision')->getSearchResults('')))
        ->toContain('Petaling Jaya');
});

it('resets the deeper address levels when the country changes', function (): void {
    $malaysia = ensureTestMalaysiaCountry();
    $geography = createTestPackageGeography('Selangor', 'Petaling', 'Petaling Jaya', country: $malaysia);
    $singapore = ensureTestAddressCountry('SG', 'Singapore');

    Livewire::test(Index::class)
        ->set('filterData.country_id', (string) $malaysia->getKey())
        ->set('filterData.state_id', (string) $geography['state']->getKey())
        ->set('filterData.area_assignments.administrative_district', (string) $geography['district']->getKey())
        ->set('filterData.country_id', (string) $singapore->getKey())
        ->assertSet('state_id', null)
        ->assertSet('area_assignments', []);
});
