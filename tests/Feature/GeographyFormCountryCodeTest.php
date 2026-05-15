<?php

use App\Filament\Resources\Districts\Pages\CreateDistrict;
use App\Filament\Resources\Districts\Schemas\DistrictForm;
use App\Filament\Resources\States\Pages\CreateState;
use App\Filament\Resources\States\Schemas\StateForm;
use App\Filament\Resources\Subdistricts\Pages\CreateSubdistrict;
use App\Filament\Resources\Subdistricts\Schemas\SubdistrictForm;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides manual country code editing on geography admin forms', function () {
    $stateComponents = collect(flattenSchemaComponents(StateForm::configure(Schema::make())->getComponents()))
        ->keyBy(componentName(...));
    $districtComponents = collect(flattenSchemaComponents(DistrictForm::configure(Schema::make())->getComponents()))
        ->keyBy(componentName(...));
    $subdistrictComponents = collect(flattenSchemaComponents(SubdistrictForm::configure(Schema::make())->getComponents()))
        ->keyBy(componentName(...));

    expect($stateComponents->get('country_code'))->toBeInstanceOf(Hidden::class)
        ->and($districtComponents->get('country_code'))->toBeInstanceOf(Hidden::class)
        ->and($subdistrictComponents->get('country_code'))->toBeInstanceOf(Hidden::class);

    expect($stateComponents->get('country_code'))->not->toBeInstanceOf(TextInput::class)
        ->and($districtComponents->get('country_code'))->not->toBeInstanceOf(TextInput::class)
        ->and($subdistrictComponents->get('country_code'))->not->toBeInstanceOf(TextInput::class);
});

it('derives geography country codes from the selected country on create', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $country = new Country;
    $country->forceFill([
        'name' => 'Testland',
        'iso2' => 'TL',
        'iso3' => 'TST',
        'phone_code' => '999',
        'region' => 'Test Region',
        'subregion' => 'Test Subregion',
        'status' => 1,
    ]);
    $country->save();

    Livewire::actingAs($administrator)
        ->test(CreateState::class)
        ->fillForm([
            'country_id' => (string) $country->getKey(),
            'name' => 'Alpha State',
            'country_code' => 'ZZ',
        ])
        ->call('create')
        ->assertHasNoErrors();

    $state = State::query()->where('name', 'Alpha State')->firstOrFail();

    expect($state->country_code)->toBe('TL');

    Livewire::actingAs($administrator)
        ->test(CreateDistrict::class)
        ->fillForm([
            'country_id' => (string) $country->getKey(),
            'state_id' => (string) $state->getKey(),
            'name' => 'Alpha District',
            'country_code' => 'ZZ',
        ])
        ->call('create')
        ->assertHasNoErrors();

    $district = District::query()->where('name', 'Alpha District')->firstOrFail();

    expect($district->country_code)->toBe('TL');

    Livewire::actingAs($administrator)
        ->test(CreateSubdistrict::class)
        ->fillForm([
            'country_id' => (string) $country->getKey(),
            'state_id' => (string) $state->getKey(),
            'district_id' => (string) $district->getKey(),
            'name' => 'Alpha Subdistrict',
            'country_code' => 'ZZ',
        ])
        ->call('create')
        ->assertHasNoErrors();

    $subdistrict = Subdistrict::query()->where('name', 'Alpha Subdistrict')->firstOrFail();

    expect($subdistrict->country_code)->toBe('TL');
});

it('allows creating federal territory subdistricts without a district', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $country = new Country;
    $country->forceFill([
        'id' => 132,
        'name' => 'Malaysia',
        'iso2' => 'MY',
        'iso3' => 'MYS',
        'phone_code' => '60',
        'region' => 'Asia',
        'subregion' => 'South-Eastern Asia',
        'status' => 1,
    ]);
    $country->save();

    $state = State::query()->create([
        'country_id' => (int) $country->getKey(),
        'name' => 'Kuala Lumpur',
        'country_code' => 'MY',
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateSubdistrict::class)
        ->fillForm([
            'country_id' => (string) $country->getKey(),
            'state_id' => (string) $state->getKey(),
            'district_id' => null,
            'name' => 'Setiawangsa',
            'country_code' => 'ZZ',
        ])
        ->call('create')
        ->assertHasNoErrors();

    $subdistrict = Subdistrict::query()->where('name', 'Setiawangsa')->firstOrFail();

    expect($subdistrict->district_id)->toBeNull()
        ->and($subdistrict->country_code)->toBe('MY');
});

function flattenSchemaComponents(array $components): array
{
    $flattened = [];

    foreach ($components as $component) {
        $flattened[] = $component;

        $reflection = new ReflectionObject($component);

        while (! $reflection->hasProperty('childComponents') && ($parent = $reflection->getParentClass())) {
            $reflection = $parent;
        }

        if (! $reflection->hasProperty('childComponents')) {
            continue;
        }

        $childComponentsProperty = $reflection->getProperty('childComponents');
        $childComponents = $childComponentsProperty->getValue($component);

        if (! is_array($childComponents)) {
            continue;
        }

        $defaultChildComponents = $childComponents['default'] ?? null;

        if (! is_array($defaultChildComponents)) {
            continue;
        }

        array_push($flattened, ...flattenSchemaComponents($defaultChildComponents));
    }

    return $flattened;
}

function componentName(mixed $component): ?string
{
    return method_exists($component, 'getName') ? $component->getName() : null;
}
