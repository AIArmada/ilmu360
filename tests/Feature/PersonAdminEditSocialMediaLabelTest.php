<?php

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\City;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\Persons\Pages\EditPerson;
use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

it('loads person edit page when person has social media row', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $person = Person::factory()->create();

    $person->socialProfiles()->create([
        'platform' => 'facebook',
        'handle' => 'atiqah',
        'url' => 'https://www.facebook.com/atiqah',
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        $this->actingAs($administrator)
            ->get(PersonResource::getUrl('edit', ['record' => $person]))
            ->assertSuccessful();
    });
});

it('saves the person edit page when a social media row only has a username', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $person = Person::factory()->create();
    $address = Address::query()->create([
        'country_id' => (string) ensureTestMalaysiaCountry()->getKey(),
        'country_code' => 'MY',
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    $person->socialProfiles()->create([
        'platform' => 'facebook',
        'handle' => 'atiqah',
        'url' => null,
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        Livewire::actingAs($administrator)
            ->test(EditPerson::class, ['record' => $person->id])
            ->call('save')
            ->assertHasNoErrors();
    });

    expect($person->fresh()->socialProfiles()->where('platform', 'facebook')->value('handle'))->toBe('atiqah');
});

it('hydrates canonical state and city selections from an existing text-only address', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $country = ensureTestMalaysiaCountry();
    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Johor',
        'code' => 'JHR',
    ]);
    $city = City::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Kota Tinggi',
    ]);

    $person = Person::factory()->create();
    $address = Address::query()->create([
        'country_id' => $country->getKey(),
        'country_code' => 'MY',
        'state' => 'Johor',
        'city' => 'Kota Tinggi',
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    OwnerContext::withOwner(null, function () use ($administrator, $person, $state, $city): void {
        Livewire::actingAs($administrator)
            ->test(EditPerson::class, ['record' => $person->id])
            ->assertSet('data.address.state_id', (string) $state->getKey())
            ->assertSet('data.address.city_id', (string) $city->getKey())
            ->assertSet('data.address.state', 'Johor')
            ->assertSet('data.address.city', 'Kota Tinggi');
    });
});

it('clears stale city text and slug suffix when an admin changes to a state without a city', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $country = ensureTestMalaysiaCountry();
    $johor = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Johor',
        'code' => 'JHR',
    ]);
    $city = City::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $johor->getKey(),
        'name' => 'Kota Tinggi',
    ]);
    $selangor = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Selangor',
        'code' => 'SGR',
    ]);

    $person = Person::factory()->create(['slug' => 'rozaimi-ramle-kota-tinggi-johor-my']);
    $address = Address::query()->create([
        'country_id' => $country->getKey(),
        'country_code' => 'MY',
        'state_id' => $johor->getKey(),
        'city_id' => $city->getKey(),
        'state' => 'Johor',
        'city' => 'Kota Tinggi',
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    OwnerContext::withOwner(null, function () use ($administrator, $person, $selangor): void {
        Livewire::actingAs($administrator)
            ->test(EditPerson::class, ['record' => $person->id])
            ->set('data.address.state_id', (string) $selangor->getKey())
            ->set('data.address.city_id', null)
            ->call('save')
            ->assertHasNoErrors();
    });

    $person->refresh();
    $address = $person->primaryAddress();

    expect($address?->state_id)->toBe((string) $selangor->getKey())
        ->and($address?->city_id)->toBeNull()
        ->and($address?->state)->toBe('Selangor')
        ->and($address?->city)->toBeNull()
        ->and($person->slug)->not->toContain('kota-tinggi')
        ->and($person->slug)->toContain('selangor-my');
});

it('persists clearing the state selection from the person edit form', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $country = ensureTestMalaysiaCountry();
    $state = State::query()->create([
        'country_id' => $country->getKey(),
        'name' => 'Johor',
        'code' => 'JHR',
    ]);
    $city = City::query()->create([
        'country_id' => $country->getKey(),
        'state_id' => $state->getKey(),
        'name' => 'Kota Tinggi',
    ]);

    $person = Person::factory()->create(['slug' => 'person-johor-kota-tinggi-my']);
    $address = Address::query()->create([
        'country_id' => $country->getKey(),
        'country_code' => 'MY',
        'state_id' => $state->getKey(),
        'city_id' => $city->getKey(),
        'state' => 'Johor',
        'city' => 'Kota Tinggi',
    ]);
    $person->attachAddress($address, type: 'primary', isPrimary: true);

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        Livewire::actingAs($administrator)
            ->test(EditPerson::class, ['record' => $person->id])
            ->set('data.address.state_id', null)
            ->call('save')
            ->assertHasNoErrors();
    });

    $person->refresh();
    $address = $person->primaryAddress();

    expect($address?->state_id)->toBeNull()
        ->and($address?->city_id)->toBeNull()
        ->and($address?->state)->toBeNull()
        ->and($address?->city)->toBeNull()
        ->and($person->slug)->not->toContain('johor')
        ->and($person->slug)->not->toContain('kota-tinggi');
});
