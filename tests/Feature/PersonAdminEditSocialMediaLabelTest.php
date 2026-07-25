<?php

use AIArmada\Addressing\Models\Address;
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
