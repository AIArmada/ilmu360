<?php

use AIArmada\CommerceSupport\Models\Role;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Filament\Resources\Institutions\RelationManagers\EventsRelationManager;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

it('defines institution events as a real Eloquent relation', function () {
    $institution = Institution::factory()->create();

    expect($institution->events())->toBeInstanceOf(HasMany::class);
});

it('renders the institution events relation manager table', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $event = Event::factory()->create([
        'institution_id' => $institution->getKey(),
    ]);

    Livewire::actingAs($administrator)
        ->test(EventsRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => EditInstitution::class,
        ])
        ->assertCanSeeTableRecords([$event])
        ->assertCanNotSeeTableRecords([Event::factory()->create()]);
});
