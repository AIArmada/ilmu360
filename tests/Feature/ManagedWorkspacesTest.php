<?php

declare(strict_types=1);

use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Services\MembershipRoleSyncService;
use App\Enums\ContributionSubjectType;
use App\Livewire\Pages\Dashboard\InstitutionDashboard;
use App\Livewire\Pages\Dashboard\PersonDashboard;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Membership\MemberInvitationNotification;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the institutions and speakers the user belongs to in the unified workspace', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Managed Institution']);
    $person = Person::factory()->create(['name' => 'Managed Speaker']);
    $outsideInstitution = Institution::factory()->create(['name' => 'Outside Institution']);
    $outsidePerson = Person::factory()->create(['name' => 'Outside Speaker']);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();

    $institution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);
    $person->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);

    $this->actingAs($user)
        ->get(route('dashboard.organizations.index'))
        ->assertOk()
        ->assertSee('Managed Institution')
        ->assertSee('Managed Speaker')
        ->assertSee(route('dashboard.institutions', ['institution' => $institution->getKey()]), false)
        ->assertSee(route('dashboard.persons', $person), false)
        ->assertDontSee('Outside Institution')
        ->assertDontSee('Outside Speaker');

    expect($outsideInstitution->members()->whereKey($user->getKey())->exists())->toBeFalse()
        ->and($outsidePerson->members()->whereKey($user->getKey())->exists())->toBeFalse();
});

it('lets speaker admins manage members while keeping viewers read only', function (): void {
    $admin = User::factory()->create();
    $viewer = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Speaker Workspace Profile',
        'status' => 'verified',
    ]);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();

    $person->members()->syncWithoutDetaching([
        $admin->id => ['role' => MemberRole::Admin->value],
        $viewer->id => ['role' => MemberRole::Viewer->value],
    ]);

    $editUrl = route('contributions.suggest-update', [
        'subjectType' => ContributionSubjectType::Person->publicRouteSegment(),
        'subjectId' => $person->slug,
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertSee('Speaker Workspace Profile')
        ->assertSee('Invite by email')
        ->assertSee('Edit speaker profile')
        ->assertSee($editUrl, false);

    $this->actingAs($viewer)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertDontSee('Invite by email')
        ->assertDontSee('Edit speaker profile');
});

it('lets speaker admins invite, change, and remove non-owner members', function (): void {
    Notification::fake();

    $admin = User::factory()->create();
    $member = User::factory()->create(['email' => 'speaker-member@example.test']);
    $person = Person::factory()->create(['name' => 'Speaker Member Management']);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    $roleSync = app(MembershipRoleSyncService::class);

    foreach (MemberRole::cases() as $role) {
        $roleSync->ensureExists($role, (string) $person->getKey(), $person::class, 'web');
    }

    $person->members()->syncWithoutDetaching([
        $admin->id => ['role' => MemberRole::Admin->value],
        $member->id => ['role' => MemberRole::Viewer->value],
    ]);

    Livewire::actingAs($admin)
        ->test(PersonDashboard::class, ['person' => $person])
        ->set('inviteEmail', 'new-speaker-member@example.test')
        ->set('inviteRole', MemberRole::Editor->value)
        ->call('invite')
        ->call('startEditingMember', $member->getKey())
        ->set('editingRole', MemberRole::Admin->value)
        ->call('saveMemberRole')
        ->call('removeMember', $member->getKey());

    expect($person->fresh()->members()->whereKey($member->getKey())->exists())->toBeFalse()
        ->and(MemberInvitation::query()->where('email', 'new-speaker-member@example.test')->where('subject_id', $person->getKey())->exists())->toBeTrue();

    Notification::assertSentOnDemand(MemberInvitationNotification::class);
});

it('does not let an admin of one institution manage another institution', function (): void {
    $user = User::factory()->create();
    $candidate = User::factory()->create(['email' => 'scoped-candidate@example.test']);
    $selectedInstitution = Institution::factory()->create(['name' => 'Selected Scope']);
    $managedInstitution = Institution::factory()->create(['name' => 'Managed Scope']);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();

    $managedInstitution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);
    $selectedInstitution->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Viewer->value]]);

    $this->actingAs($user)
        ->get(route('dashboard.institutions', ['institution' => $selectedInstitution->getKey()]))
        ->assertOk()
        ->assertDontSee('Members & Roles')
        ->assertDontSee('Add Member');

    $component = Livewire::withQueryParams(['institution' => $selectedInstitution->getKey()])
        ->actingAs($user)
        ->test(InstitutionDashboard::class);

    expect($component->instance()->canManageMembers())->toBeFalse()
        ->and($selectedInstitution->fresh()->members()->whereKey($candidate->getKey())->exists())->toBeFalse();
});

it('forbids speaker workspace access to non-members', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard.persons', $person))
        ->assertForbidden();
});
