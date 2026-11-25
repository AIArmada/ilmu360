<?php

use AIArmada\Authz\Features\Authorization\Models\Role;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource as AuthzUserResource;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\RelationManagers\MembersRelationManager;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

it('links institution members to the authz user resource from the members relation', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $institution = Institution::factory()->create();
    $member = User::factory()->create([
        'name' => 'Linked Member',
    ]);

    $institution->members()->syncWithoutDetaching([$member->getKey()]);

    Livewire::actingAs($administrator)
        ->test(MembersRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => EditInstitution::class,
        ])
        ->assertCanSeeTableRecords([$member])
        ->assertTableColumnExists(
            'name',
            fn ($column): bool => $column->getUrl() === AuthzUserResource::getUrl('edit', ['record' => $member], panel: 'admin'),
            $member,
        );
});
