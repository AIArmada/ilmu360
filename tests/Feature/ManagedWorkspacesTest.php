<?php

declare(strict_types=1);

use AIArmada\FilamentEvents\Resources\EventResource;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Membership\Services\MembershipRoleSyncService;
use App\Enums\ContributionSubjectType;
use App\Enums\EventKeyPersonRole;
use App\Livewire\Pages\Dashboard\Events\CreateAdvanced;
use App\Livewire\Pages\Dashboard\InstitutionDashboard;
use App\Livewire\Pages\Dashboard\PersonDashboard;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Membership\MemberInvitationNotification;
use App\Support\Api\Member\MemberResourceRegistry;
use App\Support\Authz\MemberPermissionGate;
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

it('shows speaker events and authorizes event editing through the speaker membership', function (): void {
    $admin = User::factory()->create();
    $viewer = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Speaker Event Workspace',
        'status' => 'verified',
    ]);
    $institution = Institution::factory()->create(['name' => 'Event Institution']);

    app(ScopedMemberRoleSeeder::class)->ensureForPerson();

    $person->members()->syncWithoutDetaching([
        $admin->id => ['role' => MemberRole::Admin->value],
        $viewer->id => ['role' => MemberRole::Viewer->value],
    ]);

    $event = Event::factory()->create([
        'title' => 'Speaker Managed Event',
        'institution_id' => $institution->id,
        'status' => 'approved',
        'visibility' => 'private',
    ]);
    EventKeyPerson::query()->create([
        'event_id' => $event->id,
        'involveable_type' => 'person',
        'involveable_id' => $person->id,
        'role_code' => EventKeyPersonRole::Speaker->value,
        'sort_order' => 1,
        'visibility' => 'public',
    ]);

    $outsideEvent = Event::factory()->create(['title' => 'Outside Speaker Event']);
    $editUrl = EventResource::getUrl('edit', ['record' => $event], panel: 'ahli');
    $createUrl = route('dashboard.events.create-advanced', ['person' => $person->id]);

    expect(app(MemberPermissionGate::class)->canEventThroughPerson($admin, 'event.update', $event))->toBeTrue()
        ->and($admin->can('update', $event))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertSee('Manage speaker events')
        ->assertSee('Speaker Managed Event')
        ->assertSee($editUrl, false)
        ->assertSee($createUrl, false)
        ->assertDontSee('Outside Speaker Event');

    $memberResourceRegistry = app(MemberResourceRegistry::class);
    $eventResource = $memberResourceRegistry->resolve('events');
    $memberEventIds = $memberResourceRegistry->queryFor((string) $eventResource)->pluck('events.id')->all();

    expect($memberEventIds)->toContain($event->id)->not->toContain($outsideEvent->id);

    $this->actingAs($viewer)
        ->get(route('dashboard.persons', $person))
        ->assertOk()
        ->assertSee('Speaker Managed Event')
        ->assertDontSee($editUrl, false)
        ->assertDontSee($createUrl, false);

    expect($admin->can('update', $event))->toBeTrue()
        ->and($viewer->can('update', $event))->toBeFalse()
        ->and($outsideEvent->persons()->whereKey($person->id)->exists())->toBeFalse();
});

it('carries the speaker workspace context into the event submission wizard', function (): void {
    $user = User::factory()->create();
    $person = Person::factory()->create([
        'name' => 'Prefilled Speaker',
        'status' => 'verified',
    ]);

    Institution::factory()->create();
    app(ScopedMemberRoleSeeder::class)->ensureForPerson();
    $person->members()->syncWithoutDetaching([$user->id => ['role' => MemberRole::Admin->value]]);

    Livewire::withQueryParams(['person' => $person->id])
        ->actingAs($user)
        ->test(CreateAdvanced::class)
        ->assertSet('prefillPersonId', $person->id)
        ->assertSee('Prefilled Speaker');

    Livewire::withQueryParams(['person' => $person->id])
        ->actingAs($user)
        ->test(Create::class)
        ->assertSet('prefillPersonId', $person->id)
        ->assertSet('data.persons', [$person->id]);
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
