<?php

use App\Livewire\Pages\Events\Index;
use App\Models\Institution;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function dietInstitutionSelectField($component): Select
{
    $field = collect($component->instance()->getForm('form')->getFlatFields())
        ->first(fn (mixed $field): bool => $field instanceof Select && $field->getName() === 'institution_id');

    expect($field)->toBeInstanceOf(Select::class);

    return $field;
}

function dietAreaSelectField($component, string $name): Select
{
    $field = collect($component->instance()->getForm('form')->getFlatFields())
        ->first(fn (mixed $field): bool => $field instanceof Select && $field->getName() === $name);

    expect($field)->toBeInstanceOf(Select::class);

    return $field;
}

it('does not preload institution options into the filter HTML', function () {
    $institution = Institution::factory()->create([
        'name' => 'Institusi Diet '.uniqid(),
        'status' => 'verified',
    ]);

    $response = $this->get(route('events.index', [], false));

    $response->assertOk();
    $response->assertDontSee($institution->name);
});

it('does not preload area options into the filter HTML', function () {
    $districtName = 'Daerah Diet '.uniqid();
    $geo = createTestPackageGeography('Negeri Diet '.uniqid(), $districtName);

    $response = $this->get(route('events.index', [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
    ], false));

    $response->assertOk();
    $response->assertDontSee($districtName);
});

it('labels selected district chips without preloaded options', function () {
    $geo = createTestPackageGeography('Negeri Diet '.uniqid(), 'Daerah Chip '.uniqid());

    $response = $this->get(route('events.index', [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'area_assignments' => ['administrative_district' => (string) $geo['district']->getKey()],
    ], false));

    $response->assertOk()
        ->assertSee($geo['district']->name);
});

it('shows scoped subdivision options only on search', function () {
    $subdivisionName = 'Mukim Diet '.uniqid();
    $geo = createTestPackageGeography('Negeri Diet '.uniqid(), 'Daerah Diet '.uniqid(), $subdivisionName);

    $response = $this->get(route('events.index', [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'area_assignments' => ['administrative_district' => (string) $geo['district']->getKey()],
    ], false));

    $response->assertOk()
        ->assertSee('Bandar / Mukim / Zon')
        ->assertDontSee($subdivisionName);
});

it('resolves null location labels without querying', function () {
    $component = Livewire::test(Index::class);

    expect($component->instance()->areaOptionLabel(null))->toBeNull()
        ->and($component->instance()->institutionOptionLabel(null))->toBeNull();
});

it('tolerates malformed institution filter values', function () {
    $this->get(route('events.index', ['institution_id' => 'xx'], false))->assertOk();
});

it('drops malformed area assignment filter values', function () {
    $this->get(route('events.index', ['area_assignments' => ['administrative_district' => 'yy']], false))
        ->assertOk();

    $component = Livewire::withQueryParams(['area_assignments' => ['administrative_district' => 'yy']])
        ->test(Index::class);

    expect($component->get('area_assignments'))->toBe([]);
});

it('keeps area assignment keys in form state for nested bindings', function () {
    $expected = [
        'administrative_division' => null,
        'postal_locality' => null,
        'administrative_district' => null,
        'administrative_subdivision' => null,
    ];

    $component = Livewire::test(Index::class);

    expect($component->get('filterData.area_assignments'))->toBe($expected);

    $component->set('filterData.country_id', (string) createTestPackageGeography('Negeri Keys '.uniqid())['country']->getKey());

    expect($component->get('filterData.area_assignments'))->toBe($expected);
});

it('searches institution options asynchronously within the selected scope', function () {
    $geoA = createTestPackageGeography('Negeri Scope A '.uniqid(), 'District A '.uniqid());
    $geoB = createTestPackageGeography('Negeri Scope B '.uniqid(), 'District B '.uniqid());

    $match = Institution::factory()->create(['name' => 'Institusi Scope A '.uniqid(), 'status' => 'verified']);
    syncPrimaryAddressForTest($match, $geoA['address']);

    $other = Institution::factory()->create(['name' => 'Institusi Scope B '.uniqid(), 'status' => 'verified']);
    syncPrimaryAddressForTest($other, $geoB['address']);

    $component = Livewire::withQueryParams([
        'country_id' => (string) $geoA['country']->getKey(),
        'state_id' => (string) $geoA['state']->getKey(),
    ])->test(Index::class);

    $results = dietInstitutionSelectField($component)->getSearchResults('Institusi Scope');

    expect($results)->toHaveKey((string) $match->getKey())
        ->and($results)->not->toHaveKey((string) $other->getKey());
});

it('searches district options asynchronously within the selected scope', function () {
    $geoA = createTestPackageGeography('Negeri Dist A '.uniqid(), 'Daerah Search A '.uniqid());
    $geoB = createTestPackageGeography('Negeri Dist B '.uniqid(), 'Daerah Search B '.uniqid());

    $component = Livewire::withQueryParams([
        'country_id' => (string) $geoA['country']->getKey(),
        'state_id' => (string) $geoA['state']->getKey(),
    ])->test(Index::class);

    $results = dietAreaSelectField($component, 'area_assignments.administrative_district')->getSearchResults('Daerah Search');

    expect($results)->toHaveKey((string) $geoA['district']->getKey())
        ->and($results)->not->toHaveKey((string) $geoB['district']->getKey());
});

it('scopes subdivision search to the selected district', function () {
    $geo = createTestPackageGeography('Negeri Sub '.uniqid(), 'Daerah Sub '.uniqid(), 'Mukim Sub A '.uniqid());
    $outside = createTestAddressArea('Mukim Sub B '.uniqid(), 3, parent: $geo['area_tree_root'], country: $geo['country']);

    $component = Livewire::withQueryParams([
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'area_assignments' => ['administrative_district' => (string) $geo['district']->getKey()],
    ])->test(Index::class);

    $results = dietAreaSelectField($component, 'area_assignments.administrative_subdivision')->getSearchResults('Mukim Sub');

    expect($results)->toHaveKey((string) $geo['subdistrict']->getKey())
        ->and($results)->not->toHaveKey((string) $outside->getKey());
});
