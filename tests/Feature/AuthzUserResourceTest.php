<?php

use AIArmada\CommerceSupport\Models\Role;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\FilamentAuthz\Facades\Authz;
use App\Enums\MemberSubjectType;
use App\Filament\Resources\Authz\UserResource;
use App\Filament\Resources\Authz\UserResource\Pages\EditUser;
use App\Filament\Resources\Authz\UserResource\Pages\ListUsers;
use App\Filament\Resources\Authz\UserResource\Pages\ViewUser;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\SavedSearch;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

it('shows timezone and verification timestamps on the authz user edit page', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create([
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($administrator)
        ->get(UserResource::getUrl('edit', ['record' => $targetUser], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Timezone')
        ->assertSee('Email Verified At')
        ->assertSee('Phone Verified At');
});

it('allows admins to create and revoke api tokens for dedicated service users from the authz user view page', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    config()->set('scramble.api_domain', 'api.ilmu360.com');
    config()->set('app.url', 'https://admin.ilmu360.com');

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $serviceUser = User::factory()->create([
        'name' => 'Copilot Service',
        'email' => 'copilot-service@example.test',
    ]);

    $this->actingAs($administrator)
        ->get(UserResource::getUrl('view', ['record' => $serviceUser], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('API Access')
        ->assertSee('https://api.ilmu360.com/docs')
        ->assertSee('Create Token')
        ->assertSee('No API tokens issued yet.');

    $component = Livewire::actingAs($administrator)
        ->test(ViewUser::class, ['record' => $serviceUser->getKey()])
        ->set('apiTokenName', 'copilot-prod')
        ->call('createApiToken')
        ->assertHasNoErrors()
        ->assertSet('apiTokenName', '')
        ->assertSet('newApiToken', fn (?string $token): bool => is_string($token) && $token !== '');

    $tokenId = $serviceUser->fresh()->tokens()->value('id');

    expect($serviceUser->fresh()->tokens()->pluck('name')->all())->toBe(['copilot-prod']);

    $component
        ->call('revokeApiToken', $tokenId)
        ->assertHasNoErrors();

    expect($serviceUser->fresh()->tokens()->count())->toBe(0);
});

it('shows read only membership summaries on the authz user edit page', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create();

    $institution = Institution::factory()->create(['name' => 'Edit Membership Institution']);
    $speaker = Speaker::factory()->create(['name' => 'Edit Membership Speaker']);
    $event = Event::factory()->create(['title' => 'Edit Membership Event']);
    $reference = Reference::factory()->create(['title' => 'Edit Membership Reference']);

    $institution->members()->syncWithoutDetaching([$targetUser->id => ['role' => 'owner']]);
    $speaker->members()->syncWithoutDetaching([$targetUser->id]);
    $event->members()->syncWithoutDetaching([$targetUser->id => ['joined_at' => now()]]);
    $reference->members()->syncWithoutDetaching([$targetUser->id]);

    $this->actingAs($administrator)
        ->get(UserResource::getUrl('edit', ['record' => $targetUser], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Memberships')
        ->assertSee('Edit Membership Institution')
        ->assertSee('Edit Membership Speaker')
        ->assertSee('Edit Membership Event')
        ->assertSee('Edit Membership Reference')
        ->assertSee('read-only', false);
});

it('shows and applies protected scoped role overrides from the authz user edit page', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create();
    $institution = Institution::factory()->create(['name' => 'Protected Role Institution']);

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    $institution->members()->syncWithoutDetaching([$targetUser->id]);

    $roleOptions = app(MemberRoleCatalog::class)->roleOptionsFor(MemberSubjectType::Institution);
    $ownerRoleId = array_search('editor', $roleOptions, true);
    $adminRoleId = array_search('admin', $roleOptions, true);

    expect($ownerRoleId)->toBeString()
        ->and($adminRoleId)->toBeString();

    Authz::withScope(app(MemberRoleScopes::class)->institution(), function () use ($targetUser): void {
        setPermissionsTeamId($targetUser->institutions()->firstOrFail()->getKey());
        $targetUser->syncRoles(['editor']);
    }, $targetUser);

    $this->actingAs($administrator)
        ->get(UserResource::getUrl('edit', ['record' => $targetUser], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Protected Scoped Roles')
        ->assertSee('Protected Role Institution');

    Livewire::actingAs($administrator)
        ->test(EditUser::class, ['record' => $targetUser->getKey()])
        ->set('protectedRoleSelections.institution', 'editor')
        ->call('applyProtectedScopedRole', 'institution')
        ->assertHasNoErrors();

    expect(app(MemberRoleCatalog::class)->roleNamesFor($targetUser->fresh(), MemberSubjectType::Institution))->toBe(['editor']);
});

it('saves authz user edits without mass assigning roles', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
        'timezone' => null,
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    $roleId = (string) Role::query()
        ->where('name', 'super_admin')
        ->whereNull(app(PermissionRegistrar::class)->teamsKey)
        ->value('id');

    Livewire::actingAs($administrator)
        ->test(EditUser::class, ['record' => $targetUser->getKey()])
        ->fillForm([
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'timezone' => 'Asia/Kuala_Lumpur',
            'email_verified_at' => now()->format('Y-m-d H:i:s'),
            'phone_verified_at' => now()->format('Y-m-d H:i:s'),
            'roles' => [$roleId],
            'permissions' => [],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $targetUser->refresh();

    expect($targetUser->name)->toBe('Updated Name')
        ->and($targetUser->email)->toBe('updated@example.com')
        ->and($targetUser->timezone)->toBe('Asia/Kuala_Lumpur')
        ->and($targetUser->email_verified_at)->not->toBeNull()
        ->and($targetUser->phone_verified_at)->not->toBeNull()
        ->and($targetUser->hasRole('super_admin'))->toBeTrue();
});

it('opens authz user records on the view page from the users index', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create([
        'name' => 'Viewed User',
    ]);

    Livewire::actingAs($administrator)
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords([$targetUser])
        ->assertTableActionExists(
            'view',
            fn ($action): bool => $action->getUrl() === UserResource::getUrl('view', ['record' => $targetUser], panel: 'admin'),
            $targetUser,
        );
});

it('allows searching the authz user index by global role name', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create([
        'name' => 'Global Role Search Target',
    ]);
    $plainUser = User::factory()->create([
        'name' => 'Plain User',
    ]);

    $targetUser->assignRole('super_admin');

    Livewire::actingAs($administrator)
        ->test(ListUsers::class)
        ->searchTable('super_admin')
        ->assertCanSeeTableRecords([$targetUser])
        ->assertCanNotSeeTableRecords([$plainUser]);
});

it('shows authz user activity memberships follows submissions and saved searches on the view page', function () {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    if (! Role::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $targetUser = User::factory()->create([
        'name' => 'Activity User',
        'email' => 'activity@example.com',
        'phone' => '+60112223344',
        'timezone' => 'Asia/Kuala_Lumpur',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $savedEvent = Event::factory()->create(['title' => 'Saved Event']);
    $goingEvent = Event::factory()->create(['title' => 'Going Event']);
    $registeredEvent = Event::factory()->create(['title' => 'Registered Event']);
    $checkedInEvent = Event::factory()->create(['title' => 'Checked In Event']);
    $submittedEvent = Event::factory()->create(['title' => 'Submitted Event']);
    $memberEvent = Event::factory()->create(['title' => 'Member Event']);

    $followedInstitution = Institution::factory()->create(['name' => 'Followed Institution']);
    $memberInstitution = Institution::factory()->create(['name' => 'Member Institution']);
    $followedSpeaker = Speaker::factory()->create(['name' => 'Followed Speaker']);
    $memberSpeaker = Speaker::factory()->create(['name' => 'Member Speaker']);
    $followedReference = Reference::factory()->create(['title' => 'Followed Reference']);

    app(EngagementManager::class)->bookmark($targetUser, $savedEvent);
    $targetUser->respond($goingEvent, 'going');
    $targetUser->memberEvents()->attach($memberEvent->id, ['joined_at' => now()]);
    $memberInstitution->members()->syncWithoutDetaching([$targetUser->id]);
    $memberSpeaker->members()->syncWithoutDetaching([$targetUser->id]);

    $targetUser->follow($followedInstitution);
    $targetUser->follow($followedSpeaker);
    $targetUser->follow($followedReference);

    Registration::factory()
        ->forRegistrant($targetUser)
        ->create([
            'event_id' => $registeredEvent->id,
            'status' => 'confirmed',
        ]);

    EventCheckin::factory()->create([
        'event_id' => $checkedInEvent->id,
        'attendee_type' => $targetUser->getMorphClass(),
        'attendee_id' => $targetUser->id,
    ]);

    EventSubmission::factory()->create([
        'event_id' => $submittedEvent->id,
        'submitter_id' => $targetUser->id,
        'submission_data' => [
            'submitter_name' => $targetUser->name,
            'notes' => 'Submitted from public form.',
        ],
    ]);

    SavedSearch::factory()->create([
        'user_id' => $targetUser->id,
        'name' => 'My Saved Search',
        'query' => 'tafsir',
        'filters' => ['time_scope' => 'upcoming', 'state' => 'selangor'],
        'notify' => 'weekly',
    ]);

    Livewire::actingAs($administrator)
        ->test(ViewUser::class, ['record' => $targetUser->getKey()])
        ->assertSee('Activity User')
        ->assertSee('Saved Event')
        ->assertSee('Going Event')
        ->assertSee('Registered Event')
        ->assertSee('Checked In Event')
        ->assertSee('Followed Institution')
        ->assertSee('Followed Speaker')
        ->assertSee('Followed Reference')
        ->assertSee('Submitted Event')
        ->assertSee('Submitted from public form.')
        ->assertSee('Member Institution')
        ->assertSee('Member Speaker')
        ->assertSee('Member Event')
        ->assertSee('My Saved Search')
        ->assertSee('tafsir');
});
