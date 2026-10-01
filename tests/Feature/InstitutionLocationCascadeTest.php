<?php

use AIArmada\Addressing\Models\AddressArea;
use AIArmada\Addressing\Models\AddressAreaRelationship;
use AIArmada\Addressing\Models\AddressAreaStateLink;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use App\Models\Institution;
use App\Support\Location\LocationSlugResolver;
use Livewire\Livewire;

use function Pest\Laravel\get;

it('resolves the two deepest provider area levels per country', function (): void {
    $resolver = app(LocationSlugResolver::class);
    $malaysiaId = (string) ensureTestMalaysiaCountry()->getKey();
    $britainId = (string) ensureTestAddressCountry('GB', 'United Kingdom')->getKey();

    // Malaysia's administrative hierarchy runs division → district →
    // subdivision; the two slots take the deepest pair.
    expect($resolver->districtRoleForCountry($malaysiaId))->toBe('administrative_district')
        ->and($resolver->subdivisionRoleForCountry($malaysiaId))->toBe('administrative_subdivision')
        ->and($resolver->districtRoleForCountry($britainId))->toBe('county')
        ->and($resolver->subdivisionRoleForCountry($britainId))->toBeNull()
        ->and($resolver->districtRoleForCountry(null))->toBeNull()
        ->and($resolver->subdivisionRoleForCountry(null))->toBeNull();
});

it('resolves Indonesian cascade roles from the provider hierarchy', function (): void {
    $indonesiaId = (string) ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62')->getKey();
    $resolver = app(LocationSlugResolver::class);

    expect($resolver->districtRoleForCountry($indonesiaId))->toBe('regency')
        ->and($resolver->subdivisionRoleForCountry($indonesiaId))->toBe('district');
});

it('filters institutions down the Indonesian provider area cascade', function (): void {
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');

    $province = State::query()->firstOrCreate(
        ['country_id' => (string) $indonesia->getKey(), 'name' => 'Jawa Barat'],
        ['code' => null],
    );
    $provinceArea = createTestAddressArea('Jawa Barat', 1, country: $indonesia, type: 'province');
    AddressAreaStateLink::query()->firstOrCreate(
        ['address_area_id' => $provinceArea->getKey(), 'state_id' => $province->getKey()],
        ['hierarchy_type' => 'administrative'],
    );

    $bandung = createTestAddressArea('Bandung', 2, parent: $provinceArea, country: $indonesia, type: 'regency');
    $bogor = createTestAddressArea('Bogor', 2, parent: $provinceArea, country: $indonesia, type: 'regency');
    $cimahi = createTestAddressArea('Cimahi', 3, parent: $bandung, country: $indonesia, type: 'district');
    createTestAddressArea('Lembang', 3, parent: $bandung, country: $indonesia, type: 'district');
    $cibinong = createTestAddressArea('Cibinong', 3, parent: $bogor, country: $indonesia, type: 'district');

    $bandungInstitution = Institution::factory()->create(['name' => 'Masjid Cimahi Raya', 'status' => 'verified']);
    syncPrimaryAddressForTest($bandungInstitution, [
        'country_id' => (string) $indonesia->getKey(),
        'state_id' => (string) $province->getKey(),
        'area_assignments' => [
            'regency' => (string) $bandung->getKey(),
            'district' => (string) $cimahi->getKey(),
        ],
    ]);

    $bogorInstitution = Institution::factory()->create(['name' => 'Masjid Cibinong Indah', 'status' => 'verified']);
    syncPrimaryAddressForTest($bogorInstitution, [
        'country_id' => (string) $indonesia->getKey(),
        'state_id' => (string) $province->getKey(),
        'area_assignments' => [
            'regency' => (string) $bogor->getKey(),
            'district' => (string) $cibinong->getKey(),
        ],
    ]);

    Livewire::test('pages.institutions.index')
        ->set('country', 'indonesia')
        ->set('state', 'jawa-barat')
        ->assertSee('Kabupaten')
        ->assertDontSee('Kabupaten / Kota')
        ->assertDontSee('Kecamatan')
        ->set('areas.regency', (string) $bandung->slug)
        ->assertSee('Kecamatan')
        ->set('areas.district', (string) $cimahi->slug)
        ->assertSet('areas.district', (string) $cimahi->slug)
        ->set('areas.regency', (string) $bogor->slug)
        ->assertSet('areas.district', null);

    get('/institusi?country=indonesia&state=jawa-barat&areas[regency]='.$bandung->slug.'&areas[district]='.$cimahi->slug)
        ->assertSuccessful()
        ->assertSee('Masjid Cimahi Raya')
        ->assertDontSee('Masjid Cibinong Indah');
});

it('filters institutions down the Singapore planning cascade without a state row', function (): void {
    $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');

    State::query()->firstOrCreate(
        ['country_id' => (string) $singapore->getKey(), 'name' => 'North West'],
        ['code' => '03'],
    );

    $central = createTestAddressArea('Central', 1, country: $singapore, type: 'region');
    $bishan = createTestAddressArea('Bishan', 2, parent: $central, country: $singapore, type: 'planning_area');

    $institution = Institution::factory()->create(['name' => 'Masjid Bishan Prihatin', 'status' => 'verified']);
    syncPrimaryAddressForTest($institution, [
        'country_id' => (string) $singapore->getKey(),
        'area_assignments' => [
            'region' => (string) $central->getKey(),
            'planning_area' => (string) $bishan->getKey(),
        ],
    ]);

    $resolver = app(LocationSlugResolver::class);

    expect($resolver->districtRoleForCountry((string) $singapore->getKey()))->toBe('region')
        ->and($resolver->subdivisionRoleForCountry((string) $singapore->getKey()))->toBe('planning_area')
        ->and($resolver->stateMaps((string) $singapore->getKey())['options'])->toBe([]);

    Livewire::test('pages.institutions.index')
        ->set('country', 'singapore')
        ->assertDontSee('institution-state-filter', false)
        ->assertSee('Wilayah Perancangan')
        ->set('areas.region', (string) $central->slug)
        ->assertSee('Kawasan Perancangan')
        ->set('areas.planning_area', (string) $bishan->slug)
        ->assertSet('areas.planning_area', (string) $bishan->slug);

    get('/institusi?country=singapore&areas[region]='.$central->slug.'&areas[planning_area]='.$bishan->slug)
        ->assertSuccessful()
        ->assertSee('Masjid Bishan Prihatin');
});

it('enables the first area filter for stateless profiles without a state selection', function (): void {
    $singapore = ensureTestAddressCountry('SG', 'Singapore', 'SGP', ['Asia/Singapore'], '65');

    $central = createTestAddressArea('Central', 1, country: $singapore, type: 'region');
    createTestAddressArea('Bishan', 2, parent: $central, country: $singapore, type: 'planning_area');

    // Livewire::set() bypasses the disabled attribute, so pin the rendered
    // markup too: a disabled slot-1 select silently strands the whole
    // stateless cascade (Singapore regions can never be picked).
    $test = Livewire::test('pages.institutions.index')
        ->set('country', 'singapore')
        ->assertSee('id="institution-district-filter"', false);

    expect($test->instance()->isDistrictFilterDisabled())->toBeFalse();

    $html = $test->html();
    $position = strpos($html, 'id="institution-district-filter"');
    expect($position)->not->toBeFalse();

    $tagStart = strrpos(substr($html, 0, $position), '<select');
    $tag = substr($tagStart === false ? '' : substr($html, $tagStart, $position - $tagStart + 256), 0);
    $tag = substr($tag, 0, (int) strpos($tag, '>') + 1);

    // Standalone disabled attribute only: the class list legitimately
    // contains `disabled:` variant prefixes.
    expect($tag)->not->toMatch('/\sdisabled(\s|=|>|\/)/');
});

it('keeps the first area filter disabled until a state is picked where states exist', function (): void {
    createTestPackageGeography('Melaka Gate', 'Alor Gajah Gate', 'Masjid Tanah Gate');

    $test = Livewire::test('pages.institutions.index')
        ->set('country', 'malaysia');

    expect($test->instance()->isDistrictFilterDisabled())->toBeTrue();

    $test->set('state', 'melaka-gate');

    expect($test->instance()->isDistrictFilterDisabled())->toBeFalse();
});

it('hides the city row when a provider district profile applies', function (): void {
    $indonesia = ensureTestAddressCountry('ID', 'Indonesia', 'IDN', ['Asia/Jakarta'], '62');

    $province = State::query()->firstOrCreate(
        ['country_id' => (string) $indonesia->getKey(), 'name' => 'Jawa Barat'],
        ['code' => null],
    );
    $provinceArea = createTestAddressArea('Jawa Barat', 1, country: $indonesia, type: 'province');
    AddressAreaStateLink::query()->firstOrCreate(
        ['address_area_id' => $provinceArea->getKey(), 'state_id' => $province->getKey()],
        ['hierarchy_type' => 'administrative'],
    );
    createTestAddressArea('Bandung', 2, parent: $provinceArea, country: $indonesia, type: 'regency');

    City::query()->firstOrCreate(
        ['country_id' => (string) $indonesia->getKey(), 'state_id' => (string) $province->getKey(), 'name' => 'Bandung'],
    );

    Livewire::test('pages.institutions.index')
        ->set('country', 'indonesia')
        ->set('state', 'jawa-barat')
        ->assertSee('Kabupaten')
        ->assertDontSee('Kabupaten / Kota')
        ->assertDontSee('institution-city-filter', false);
});

it('gates the Malaysian subdivision filter on the district where districts own mukim rows', function (): void {
    $geo = createTestPackageGeography('Segamat Gate', 'Segamat District Gate', 'Jementah Gate');
    $labis = createTestAddressArea('Labis District Gate', 2, parent: $geo['area_tree_root'], country: $geo['country'], type: 'district');
    createTestAddressArea('Labis Town Gate', 3, parent: $labis, country: $geo['country'], type: 'subdistrict');

    $resolver = app(LocationSlugResolver::class);
    $countryId = (string) $geo['country']->getKey();

    expect($resolver->areaEffectiveParentRoleForCountry($countryId, 'administrative_subdivision', (string) $geo['state']->getKey()))
        ->toBe('administrative_district')
        ->and($resolver->areaSuccessorRolesForCountry($countryId, 'administrative_district'))
        ->toBe(['administrative_subdivision', 'postal_locality']);

    $test = Livewire::test('pages.institutions.index')
        ->set('country', 'malaysia')
        ->set('state', 'segamat-gate')
        ->assertSee('id="institution-district-filter"', false)
        ->assertDontSee('id="institution-subdistrict-filter"', false);

    expect($test->instance()->areaFilters()[0]['label'])->toBe('Daerah');

    $test->set('areas.administrative_district', (string) $geo['district']->slug)
        ->assertSee('id="institution-subdistrict-filter"', false)
        ->assertSee('Jementah Gate')
        ->assertDontSee('Labis Town Gate');

    expect($test->instance()->areaFilters()[1]['label'])->toBe('Daerah Kecil');

    $test->set('areas.administrative_subdivision', (string) $geo['subdistrict']->slug)
        ->assertSet('areas.administrative_subdivision', (string) $geo['subdistrict']->slug)
        ->set('areas.administrative_district', (string) $labis->slug)
        ->assertSet('areas.administrative_subdivision', null);
});

it('keeps the subdivision filter state-gated where no districts exist', function (): void {
    $country = ensureTestMalaysiaCountry();
    $state = State::query()->firstOrCreate(
        ['country_id' => (string) $country->getKey(), 'name' => 'KL Gate'],
        ['code' => null],
    );
    $root = createTestAddressArea('KL Gate', 1, country: $country, type: 'wilayah_persekutuan');
    AddressAreaStateLink::query()->firstOrCreate(
        ['address_area_id' => $root->getKey(), 'state_id' => $state->getKey()],
        ['hierarchy_type' => 'administrative'],
    );
    createTestAddressArea('Mukim KL Gate', 2, parent: $root, country: $country, type: 'mukim');

    $resolver = app(LocationSlugResolver::class);

    expect($resolver->areaEffectiveParentRoleForCountry((string) $country->getKey(), 'administrative_subdivision', (string) $state->getKey()))
        ->toBe('state');

    $test = Livewire::test('pages.institutions.index')
        ->set('country', 'malaysia')
        ->set('state', 'kl-gate')
        ->assertSee('wire:model.live="areas.administrative_subdivision"', false)
        ->assertDontSee('wire:model.live="areas.administrative_district"', false)
        ->assertDontSee('id="institution-subdivision-locality-filter"', false)
        ->assertSee('Mukim KL Gate');

    expect($test->instance()->areaFilters()[0]['label'])->toBe('Mukim');
});

it('labels a precinct-only federal territory precisely', function (): void {
    $country = ensureTestMalaysiaCountry();
    $state = State::query()->firstOrCreate(
        ['country_id' => (string) $country->getKey(), 'name' => 'Putrajaya Gate'],
        ['code' => '16'],
    );
    $root = createTestAddressArea('Putrajaya Gate', 1, country: $country, type: 'wilayah_persekutuan');
    AddressAreaStateLink::query()->firstOrCreate(
        ['address_area_id' => $root->getKey(), 'state_id' => $state->getKey()],
        ['hierarchy_type' => 'postal'],
    );
    $precinct = createTestAddressArea('Precinct 9 Gate', 2, country: $country, type: 'precinct');
    AddressAreaRelationship::query()->firstOrCreate(
        [
            'parent_address_area_id' => $root->getKey(),
            'child_address_area_id' => $precinct->getKey(),
        ],
        [
            'relationship_type' => 'contains',
            'hierarchy_type' => 'postal',
            'source' => 'tests',
        ],
    );

    $test = Livewire::test('pages.institutions.index')
        ->set('country', 'malaysia')
        ->set('state', 'putrajaya-gate')
        ->assertSee('id="institution-locality-filter"', false)
        ->assertDontSee('id="institution-subdivision-locality-filter"', false)
        ->assertSee('Precinct 9 Gate');

    expect($test->instance()->areaFilters())->toBe([])
        ->and($test->instance()->localityLabel())->toBe('Presint')
        ->and($test->instance()->groupedSubdivisionLocality())->toBeNull();
});

it('gates the Malaysian locality filter on the district where districts own locality rows', function (): void {
    $geo = createTestPackageGeography('Johor Locality Gate', 'Batu Pahat Gate', 'Parit Sulong Gate');
    $otherDistrict = createTestAddressArea('Johor Bahru Gate', 2, parent: $geo['area_tree_root'], country: $geo['country'], type: 'district');
    $localTown = createTestAddressArea('Semerah Gate', 3, country: $geo['country'], type: 'locality');
    $remoteTown = createTestAddressArea('Gelang Patah Gate', 3, country: $geo['country'], type: 'locality');
    $remoteTownSibling = createTestAddressArea('Ulu Choh Gate', 3, country: $geo['country'], type: 'locality');

    $linkPostal = static function (AddressArea $parent, AddressArea $child): void {
        AddressAreaRelationship::query()->create([
            'parent_address_area_id' => $parent->getKey(),
            'child_address_area_id' => $child->getKey(),
            'relationship_type' => 'contains',
            'hierarchy_type' => 'postal',
            'source' => 'tests',
        ]);
    };

    $linkPostal($geo['district'], $localTown);
    $linkPostal($geo['area_tree_root'], $localTown);
    $linkPostal($otherDistrict, $remoteTown);
    $linkPostal($geo['area_tree_root'], $remoteTown);
    $linkPostal($otherDistrict, $remoteTownSibling);
    $linkPostal($geo['area_tree_root'], $remoteTownSibling);

    $resolver = app(LocationSlugResolver::class);
    $countryId = (string) $geo['country']->getKey();

    expect($resolver->areaEffectiveParentRoleForCountry($countryId, 'postal_locality', (string) $geo['state']->getKey()))
        ->toBe('administrative_district')
        ->and($resolver->areaSuccessorRolesForCountry($countryId, 'administrative_district'))
        ->toBe(['administrative_subdivision', 'postal_locality']);

    $test = Livewire::test('pages.institutions.index')
        ->set('country', 'malaysia')
        ->set('state', 'johor-locality-gate')
        ->assertSee('id="institution-district-filter"', false)
        ->assertDontSee('id="institution-subdivision-locality-filter"', false)
        ->assertDontSee('id="institution-locality-filter"', false);

    $test->set('areas.administrative_district', (string) $geo['district']->slug)
        ->assertSee('id="institution-subdivision-locality-filter"', false)
        ->assertDontSee('id="institution-subdistrict-filter"', false)
        ->assertDontSee('id="institution-locality-filter"', false)
        ->assertSee('Parit Sulong Gate')
        ->assertSee('Semerah Gate')
        ->assertDontSee('Gelang Patah Gate');

    expect($test->instance()->groupedSubdivisionLocality())->not->toBeNull()
        ->and($test->instance()->groupedSubdivisionLocality()['subdivisions'])->toHaveKey((string) $geo['subdistrict']->slug)
        ->and($test->instance()->groupedSubdivisionLocality()['localities'])->toHaveKey((string) $localTown->slug);

    $test->call('selectGroupedSubdivisionLocality', 'locality:'.$localTown->slug)
        ->assertSet('locality', (string) $localTown->slug)
        ->call('selectGroupedSubdivisionLocality', 'subdivision:'.$geo['subdistrict']->slug)
        ->assertSet('areas.administrative_subdivision', (string) $geo['subdistrict']->slug)
        ->assertSet('locality', null)
        ->set('areas.administrative_district', (string) $otherDistrict->slug)
        ->assertSet('areas.administrative_subdivision', null)
        ->assertSet('locality', null)
        ->assertSee('Gelang Patah Gate')
        ->assertSee('Ulu Choh Gate')
        ->assertDontSee('Semerah Gate')
        ->assertDontSee('Parit Sulong Gate');
});

it('renders separate subdivision and locality filters when the app ungroups them', function (): void {
    $geo = createTestPackageGeography('Johor Ungrouped Gate', 'Batu Pahat Ungrouped', 'Parit Sulong Ungrouped');
    $localTown = createTestAddressArea('Semerah Ungrouped', 3, country: $geo['country'], type: 'locality');

    $linkPostal = static function (AddressArea $parent, AddressArea $child): void {
        AddressAreaRelationship::query()->create([
            'parent_address_area_id' => $parent->getKey(),
            'child_address_area_id' => $child->getKey(),
            'relationship_type' => 'contains',
            'hierarchy_type' => 'postal',
            'source' => 'tests',
        ]);
    };

    $linkPostal($geo['district'], $localTown);
    $linkPostal($geo['area_tree_root'], $localTown);

    config(['addressing.fields.group_subdivision_locality' => false]);

    try {
        Livewire::test('pages.institutions.index')
            ->set('country', 'malaysia')
            ->set('state', 'johor-ungrouped-gate')
            ->set('areas.administrative_district', (string) $geo['district']->slug)
            ->assertSee('id="institution-subdistrict-filter"', false)
            ->assertSee('id="institution-locality-filter"', false)
            ->assertDontSee('id="institution-subdivision-locality-filter"', false)
            ->assertSee('Parit Sulong Ungrouped')
            ->assertSee('Semerah Ungrouped');
    } finally {
        config(['addressing.fields.group_subdivision_locality' => true]);
    }
});
