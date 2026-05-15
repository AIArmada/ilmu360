<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Filament\Ahli\Resources\Institutions\Pages\EditInstitution as AhliEditInstitution;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Filament\Resources\Institutions\RelationManagers\MemberInvitationsRelationManager as InstitutionMemberInvitationsRelationManager;
use App\Livewire\Pages\Membership\ShowInvitation;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Support\Authz\MemberInvitationGate;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(ScopedMemberRolesSeeder::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function assignInvitationUiSuperAdmin(User $user): void
{
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->assignRole('super_admin');
    setPermissionsTeamId($previousTeam);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function assignInvitationUiGlobalRole(User $user, string $roleName): void
{
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->syncRoles([$roleName]);
    setPermissionsTeamId($previousTeam);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function assignInvitationUiScopedRole(User $user, string $scope, string $roleName): void
{
    Authz::withScope($scope, function () use ($user, $roleName): void {
        Role::findOrCreate($roleName, 'web');
        $user->syncRoles([$roleName]);
    }, $user);

    $user->refresh();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('lets institution admins create and revoke institution member invitations from the ahli relation manager', function () {
    $administrator = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$administrator->id]);
    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    assignInvitationUiScopedRole($administrator, app(MemberRoleScopes::class)->institution(), 'admin');

    Livewire::actingAs($administrator)
        ->test(InstitutionMemberInvitationsRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => AhliEditInstitution::class,
        ])
        ->callTableAction('inviteMember', data: [
            'email' => 'invitee@example.com',
            'role_slug' => 'admin',
        ])
        ->assertHasNoTableActionErrors();

    $invitation = MemberInvitation::query()->latest('created_at')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation?->subject_id)->toBe($institution->getKey())
        ->and($invitation?->email)->toBe('invitee@example.com')
        ->and($invitation?->role_slug)->toBe('admin')
        ->and($invitation?->revoked_at)->toBeNull();

    Livewire::actingAs($administrator)
        ->test(InstitutionMemberInvitationsRelationManager::class, [
            'ownerRecord' => $institution,
            'pageClass' => AhliEditInstitution::class,
        ])
        ->callTableAction('revokeInvitation', $invitation?->getKey());

    expect($invitation?->fresh()?->revoked_at)->not->toBeNull();
});

it('hides institution invitation management from global admins', function () {
    $administrator = User::factory()->create();
    $institution = Institution::factory()->create();

    assignInvitationUiSuperAdmin($administrator);

    auth()->login($administrator);

    expect(InstitutionMemberInvitationsRelationManager::canViewForRecord($institution, EditInstitution::class))
        ->toBeFalse();
});

it('does not allow moderators to invite event members', function () {
    $moderator = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'draft',
        'visibility' => 'private',
    ]);

    $gate = app(MemberInvitationGate::class);

    assignInvitationUiSuperAdmin($moderator);
    assignInvitationUiGlobalRole($moderator, 'moderator');

    expect($gate->canInvite($moderator, $event))->toBeFalse();
});

it('redirects guests to login for member invitation pages', function () {
    $institution = Institution::factory()->create();
    $inviter = User::factory()->create();

    $invitation = MemberInvitation::query()->create([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => 'invitee@example.com',
        'role_slug' => 'viewer',
        'token' => 'member-invite-token',
        'invited_by' => $inviter->getKey(),
    ]);

    $this->get(route('member-invitations.show', ['token' => $invitation->token]))
        ->assertRedirect(route('login'));
});

it('lets invitees accept member invitations from the invitation page', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invitation = MemberInvitation::query()->create([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => $invitee->email,
        'role_slug' => 'admin',
        'token' => 'member-invite-token-accept',
        'invited_by' => $inviter->getKey(),
    ]);

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $invitation->token,
        ])
        ->call('accept')
        ->assertRedirect(route('institutions.show', $institution));

    expect($institution->members()->whereKey($invitee->getKey())->exists())->toBeTrue()
        ->and($invitation->fresh()?->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()?->accepted_by)->toBe($invitee->getKey());
});

it('shows a clear message when the signed-in user has no email for the invitation', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => null,
    ]);

    $invitation = MemberInvitation::query()->create([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => 'invitee@example.com',
        'role_slug' => 'viewer',
        'token' => 'member-invite-token-no-email',
        'invited_by' => $inviter->getKey(),
    ]);

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $invitation->token,
        ])
        ->assertSee('Add an email address to your account before accepting this invitation.');
});

it('shows invalid messaging for protected invitations that should no longer be accepted', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invitation = MemberInvitation::query()->create([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => $invitee->email,
        'role_slug' => 'owner',
        'token' => 'member-invite-token-protected-owner',
        'invited_by' => $inviter->getKey(),
    ]);

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $invitation->token,
        ])
        ->assertSee('This invitation is no longer valid.');
});

it('shows invalid messaging when the invited subject no longer exists', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invitation = MemberInvitation::query()->create([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => $invitee->email,
        'role_slug' => 'viewer',
        'token' => 'member-invite-token-missing-subject',
        'invited_by' => $inviter->getKey(),
    ]);

    $institution->delete();

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $invitation->token,
        ])
        ->assertSee('This invitation is no longer valid.')
        ->assertSee(route('home'), false);
});
