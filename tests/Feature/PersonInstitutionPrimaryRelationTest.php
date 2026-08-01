<?php

use App\Filament\Resources\Persons\Pages\EditPerson;
use App\Filament\Resources\Persons\RelationManagers\InstitutionsRelationManager;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('demotes the previous primary when an admin edits a person institution affiliation', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $person = Person::factory()->create();
    $firstInstitution = Institution::factory()->create();
    $secondInstitution = Institution::factory()->create();

    $person->institutions()->attach($firstInstitution, [
        'id' => (string) Str::uuid(),
        'position' => 'First',
        'is_primary' => true,
    ]);
    $person->institutions()->attach($secondInstitution, [
        'id' => (string) Str::uuid(),
        'position' => 'Second',
        'is_primary' => false,
    ]);

    Livewire::actingAs($administrator)
        ->test(InstitutionsRelationManager::class, [
            'ownerRecord' => $person,
            'pageClass' => EditPerson::class,
        ])
        ->callTableAction('edit', $secondInstitution->getKey(), data: [
            'position' => 'Second updated',
            'is_primary' => true,
            'joined_at' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($person->institutions()->wherePivot('is_primary', true)->pluck('institutions.id')->all())
        ->toBe([$secondInstitution->getKey()]);
});

it('demotes the previous primary when an admin attaches a primary institution affiliation', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $person = Person::factory()->create();
    $firstInstitution = Institution::factory()->create();
    $secondInstitution = Institution::factory()->create();

    $person->institutions()->attach($firstInstitution, [
        'id' => (string) Str::uuid(),
        'position' => 'First',
        'is_primary' => true,
    ]);

    Livewire::actingAs($administrator)
        ->test(InstitutionsRelationManager::class, [
            'ownerRecord' => $person,
            'pageClass' => EditPerson::class,
        ])
        ->callTableAction('attach', data: [
            'recordId' => $secondInstitution->getKey(),
            'position' => 'Second',
            'is_primary' => true,
            'joined_at' => null,
        ])
        ->assertHasNoTableActionErrors();

    expect($person->institutions()->wherePivot('is_primary', true)->pluck('institutions.id')->all())
        ->toBe([$secondInstitution->getKey()]);
});
