<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\EventUsersRelationManager;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Filament\Resources\Institutions\RelationManagers\MembersRelationManager as InstitutionMembersRelationManager;
use App\Filament\Resources\References\Pages\EditReference;
use App\Filament\Resources\References\RelationManagers\MembersRelationManager as ReferenceMembersRelationManager;
use App\Filament\Resources\Speakers\Pages\EditSpeaker;
use App\Filament\Resources\Speakers\RelationManagers\MembersRelationManager as SpeakerMembersRelationManager;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleScopes;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(ScopedMemberRolesSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function assignGlobalSuperAdmin(User $user): void
{
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->assignRole('super_admin');
}

it('prepopulates existing institution member roles in the manage roles modal', function () {
    $administrator = User::factory()->create();
    assignGlobalSuperAdmin($administrator);

    $institution = Institution::factory()->create();
    $member = User::factory()->create();

    $institution->members()->syncWithoutDetaching([$member->getKey()]);

    $scope = app(MemberRoleScopes::class)->institution();

    $adminRoleId = Authz::withScope($scope, function () use ($member): string {
        $member->syncRoles(['admin']);

        return (string) Role::findByName('admin', 'web')->getKey();
    }, $member);

    Livewire::actingAs($administrator)
        ->test(InstitutionMembersRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => EditInstitution::class,
        ])
        ->mountTableAction('manageRoles', $member->getKey())
        ->assertTableActionDataSet([
            'role_id' => $adminRoleId,
        ]);
});

it('prepopulates existing speaker member roles in the manage roles modal', function () {
    $administrator = User::factory()->create();
    assignGlobalSuperAdmin($administrator);

    $speaker = Speaker::factory()->create();
    $member = User::factory()->create();

    $speaker->members()->syncWithoutDetaching([$member->getKey()]);

    $scope = app(MemberRoleScopes::class)->speaker();

    $adminRoleId = Authz::withScope($scope, function () use ($member): string {
        $member->syncRoles(['admin']);

        return (string) Role::findByName('admin', 'web')->getKey();
    }, $member);

    Livewire::actingAs($administrator)
        ->test(SpeakerMembersRelationManager::class, [
            'ownerRecord' => $speaker,
            'pageClass' => EditSpeaker::class,
        ])
        ->mountTableAction('manageRoles', $member->getKey())
        ->assertTableActionDataSet([
            'role_id' => $adminRoleId,
        ]);
});

it('prepopulates existing event member roles in the manage roles modal', function () {
    $administrator = User::factory()->create();
    assignGlobalSuperAdmin($administrator);

    $event = Event::factory()->create();
    $member = User::factory()->create();

    $event->members()->syncWithoutDetaching([
        $member->getKey() => ['joined_at' => now()],
    ]);

    $scope = app(MemberRoleScopes::class)->event();

    $coOrganizerRoleId = Authz::withScope($scope, function () use ($member): string {
        $member->syncRoles(['co-organizer']);

        return (string) Role::findByName('co-organizer', 'web')->getKey();
    }, $member);

    Livewire::actingAs($administrator)
        ->test(EventUsersRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
        ->mountTableAction('manageRoles', $member->getKey())
        ->assertTableActionDataSet([
            'role_id' => $coOrganizerRoleId,
        ]);
});

it('hides local member role actions for protected ownership roles', function () {
    $administrator = User::factory()->create();
    assignGlobalSuperAdmin($administrator);

    $institution = Institution::factory()->create();
    $speaker = Speaker::factory()->create();
    $event = Event::factory()->create();
    $reference = Reference::factory()->create();

    $institutionOwner = User::factory()->create();
    $speakerOwner = User::factory()->create();
    $eventOrganizer = User::factory()->create();
    $referenceOwner = User::factory()->create();

    $institution->members()->syncWithoutDetaching([$institutionOwner->getKey()]);
    $speaker->members()->syncWithoutDetaching([$speakerOwner->getKey()]);
    $event->members()->syncWithoutDetaching([
        $eventOrganizer->getKey() => ['joined_at' => now()],
    ]);
    $reference->members()->syncWithoutDetaching([$referenceOwner->getKey()]);

    Authz::withScope(app(MemberRoleScopes::class)->institution(), function () use ($institutionOwner): void {
        $institutionOwner->syncRoles(['owner']);
    }, $institutionOwner);

    Authz::withScope(app(MemberRoleScopes::class)->speaker(), function () use ($speakerOwner): void {
        $speakerOwner->syncRoles(['owner']);
    }, $speakerOwner);

    Authz::withScope(app(MemberRoleScopes::class)->event(), function () use ($eventOrganizer): void {
        $eventOrganizer->syncRoles(['organizer']);
    }, $eventOrganizer);

    Authz::withScope(app(MemberRoleScopes::class)->reference(), function () use ($referenceOwner): void {
        $referenceOwner->syncRoles(['owner']);
    }, $referenceOwner);

    Livewire::actingAs($administrator)
        ->test(InstitutionMembersRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => EditInstitution::class,
        ])
        ->assertTableActionHidden('manageRoles', $institutionOwner->getKey())
        ->assertTableActionHidden('removeMember', $institutionOwner->getKey());

    Livewire::actingAs($administrator)
        ->test(SpeakerMembersRelationManager::class, [
            'ownerRecord' => $speaker,
            'pageClass' => EditSpeaker::class,
        ])
        ->assertTableActionHidden('manageRoles', $speakerOwner->getKey())
        ->assertTableActionHidden('removeMember', $speakerOwner->getKey());

    Livewire::actingAs($administrator)
        ->test(EventUsersRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => EditEvent::class,
        ])
        ->assertTableActionHidden('manageRoles', $eventOrganizer->getKey())
        ->assertTableActionHidden('removeMember', $eventOrganizer->getKey());

    Livewire::actingAs($administrator)
        ->test(ReferenceMembersRelationManager::class, [
            'ownerRecord' => $reference,
            'pageClass' => EditReference::class,
        ])
        ->assertTableActionHidden('manageRoles', $referenceOwner->getKey())
        ->assertTableActionHidden('removeMember', $referenceOwner->getKey());
});
