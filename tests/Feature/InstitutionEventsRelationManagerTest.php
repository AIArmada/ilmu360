<?php

use AIArmada\Authz\Models\Role;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\RelationManagers\EventsRelationManager;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use App\Shared\Events\Enums\EventFormat;
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
        'delivery_mode' => EventFormat::Online->value,
    ]);
    $physicalEvent = Event::factory()->create([
        'institution_id' => $institution->getKey(),
        'delivery_mode' => EventFormat::Physical->value,
    ]);
    $unrelatedEvent = Event::factory()->create(['delivery_mode' => EventFormat::Online->value]);

    Livewire::actingAs($administrator)
        ->test(EventsRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => EditInstitution::class,
        ])
        ->assertCanSeeTableRecords([$event, $physicalEvent])
        ->assertTableColumnFormattedStateSet('event_format', __('Online'), $event)
        ->filterTable('event_format', EventFormat::Online)
        ->assertCanSeeTableRecords([$event])
        ->assertCanNotSeeTableRecords([$physicalEvent, $unrelatedEvent]);
});
