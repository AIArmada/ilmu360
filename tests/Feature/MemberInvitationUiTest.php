<?php

use AIArmada\Membership\Actions\InviteMemberAction;
use AIArmada\Membership\Actions\RevokeInvitationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Filament\Resources\Institutions\RelationManagers\MemberInvitationsRelationManager as InstitutionMemberInvitationsRelationManager;
use App\Livewire\Pages\Membership\ShowInvitation;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\User;
use App\Support\Authz\MemberInvitationGate;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
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

it('lets institution admins create and revoke institution member invitations from the ahli relation manager', function () {
    $administrator = User::factory()->create();
    $institution = Institution::factory()->create();

    addTestMember($institution, $administrator, MemberRole::Admin);

    expect(app(MemberInvitationGate::class)->canInvite($administrator, $institution))
        ->toBeTrue();

    $invitation = app(InviteMemberAction::class)->handle(
        $institution,
        'invitee@example.com',
        MemberRole::Admin,
        $administrator,
    );

    expect($invitation)->not->toBeNull()
        ->and($invitation?->subject_id)->toBe($institution->getKey())
        ->and($invitation?->email)->toBe('invitee@example.com')
        ->and($invitation?->role)->toBe(MemberRole::Admin->spatieRoleName())
        ->and($invitation?->revoked_at)->toBeNull();

    app(RevokeInvitationAction::class)->handle($invitation, $administrator);

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

    $rawToken = 'member-invite-token';
    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => 'invitee@example.com',
        'role' => 'viewer',
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue($rawToken)->save();

    $this->get(route('member-invitations.show', ['token' => $rawToken]))
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

    $rawToken = 'member-invite-token-accept';
    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => $invitee->email,
        'role' => 'admin',
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue($rawToken)->save();

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $rawToken,
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

    $rawToken = 'member-invite-token-no-email';
    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => 'invitee@example.com',
        'role' => 'viewer',
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue($rawToken)->save();

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $rawToken,
        ])
        ->assertSee('Add an email address to your account before accepting this invitation.')
        ->call('accept');

    expect($invitation->fresh()?->accepted_at)->toBeNull();
});

it('shows invalid messaging for protected invitations that should no longer be accepted', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $rawToken = 'member-invite-token-protected-owner';
    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => $invitee->email,
        'role' => 'owner',
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue($rawToken)->save();

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $rawToken,
        ])
        ->assertSee('This invitation is no longer valid.')
        ->call('accept');

    expect($invitation->fresh()?->accepted_at)->toBeNull();
});

it('shows invalid messaging when the invited subject no longer exists', function () {
    $institution = Institution::factory()->create([
        'status' => 'verified',
    ]);
    $inviter = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $rawToken = 'member-invite-token-missing-subject';
    $invitation = new MemberInvitation;
    $invitation->fill([
        'subject_type' => 'institution',
        'subject_id' => $institution->getKey(),
        'email' => $invitee->email,
        'role' => 'viewer',
        'invited_by' => $inviter->getKey(),
    ]);
    $invitation->issue($rawToken)->save();

    $institution->delete();

    Livewire::actingAs($invitee)
        ->test(ShowInvitation::class, [
            'token' => $rawToken,
        ])
        ->assertSee('This invitation is no longer valid.')
        ->assertSee(route('home'), false);
});
