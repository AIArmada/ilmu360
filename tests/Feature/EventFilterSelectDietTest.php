<?php

use App\Livewire\Pages\Events\Index;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function dietCitySelectField($component): Select
{
    $field = collect($component->instance()->getForm('form')->getFlatFields())
        ->first(fn (mixed $field): bool => $field instanceof Select && $field->getName() === 'city_id');

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

it('does not preload city options into the filter HTML', function () {
    $geo = createTestPackageGeography('Negeri Diet '.uniqid(), 'District Diet '.uniqid(), null, 'Kota Diet '.uniqid());

    $response = $this->get(route('events.index', [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
    ], false));

    $response->assertOk();
    $response->assertDontSee($geo['city']->name);
    // The cascade roots stay preloaded.
    $response->assertSee($geo['state']->name);
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

it('labels selected city and district chips without preloaded options', function () {
    $geo = createTestPackageGeography('Negeri Diet '.uniqid(), 'Daerah Chip '.uniqid(), null, 'Kota Chip '.uniqid());

    $response = $this->get(route('events.index', [
        'country_id' => (string) $geo['country']->getKey(),
        'state_id' => (string) $geo['state']->getKey(),
        'city_id' => (string) $geo['city']->getKey(),
        'area_assignments' => ['administrative_district' => (string) $geo['district']->getKey()],
    ], false));

    $response->assertOk()
        ->assertSee($geo['city']->name)
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

it('tolerates malformed city filter values', function () {
    $this->get(route('events.index', ['city_id' => 'xx'], false))->assertOk();
});

it('resolves null location labels without querying', function () {
    $component = Livewire::test(Index::class);

    expect($component->instance()->cityOptionLabel(null))->toBeNull()
        ->and($component->instance()->areaOptionLabel(null))->toBeNull()
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

it('searches city options asynchronously within the selected scope', function () {
    $geoA = createTestPackageGeography('Negeri Scope A '.uniqid(), 'District A '.uniqid(), null, 'Kota Scope A '.uniqid());
    $geoB = createTestPackageGeography('Negeri Scope B '.uniqid(), 'District B '.uniqid(), null, 'Kota Scope B '.uniqid());

    $component = Livewire::withQueryParams([
        'country_id' => (string) $geoA['country']->getKey(),
        'state_id' => (string) $geoA['state']->getKey(),
    ])->test(Index::class);

    $results = dietCitySelectField($component)->getSearchResults('Kota Scope');

    expect($results)->toHaveKey((string) $geoA['city']->getKey())
        ->and($results)->not->toHaveKey((string) $geoB['city']->getKey());
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
