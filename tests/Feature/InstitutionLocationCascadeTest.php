<?php

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
        ->and($resolver->districtRoleForCountry($britainId))->toBeNull()
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

it('filters institutions down the Indonesian province regency district cascade', function (): void {
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
        ->assertSee('Kabupaten / Kota')
        ->set('district', (string) $bandung->slug)
        ->assertSee('Daerah')
        ->set('subdivision', (string) $cimahi->slug)
        ->assertSet('subdivision', (string) $cimahi->slug);

    get('/institusi?country=indonesia&state=jawa-barat&district='.$bandung->slug.'&subdivision='.$cimahi->slug)
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
        ->set('district', (string) $central->slug)
        ->assertSee('Kawasan Perancangan')
        ->set('subdivision', (string) $bishan->slug)
        ->assertSet('subdivision', (string) $bishan->slug);

    get('/institusi?country=singapore&district='.$central->slug.'&subdivision='.$bishan->slug)
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
        ->assertSee('Kabupaten / Kota')
        ->assertDontSee('institution-city-filter', false);
});
