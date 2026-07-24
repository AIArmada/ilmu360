<?php

use App\Filament\Resources\Persons\Pages\ListPersons;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

it('shows follower count on admin persons list', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $person = Person::factory()->create();

    User::factory()->count(2)->create()->each(fn (User $user) => $user->follow($person));

    $this->actingAs($administrator);

    Livewire::test(ListPersons::class)
        ->assertSee($person->name)
        ->assertSee('2');
});
