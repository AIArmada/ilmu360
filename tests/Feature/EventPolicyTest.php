<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Models\Event;
use App\Models\EventUser;
use App\Models\User;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Draft;
use App\States\EventStatus\Pending;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Ensure teams mode is ON for Authz scoping
    config(['permission.teams' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Create global roles (unscoped) for admin/moderator
    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $roleClass = app(PermissionRegistrar::class)->getRoleClass();

    if (! $roleClass::where('name', 'super_admin')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        $roleClass::create(['name' => 'super_admin', 'guard_name' => 'web']);
    }
    if (! $roleClass::where('name', 'moderator')->whereNull(app(PermissionRegistrar::class)->teamsKey)->exists()) {
        $roleClass::create(['name' => 'moderator', 'guard_name' => 'web']);
    }

    setPermissionsTeamId($previousTeam);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Helper: assign a shared event-member scoped role to a user.
 */
function assignEventScopedRole(User $user, string $roleName): void
{
    app(ScopedMemberRoleSeeder::class)->ensureForEvent();
    $scope = app(MemberRoleScopes::class)->event();

    Authz::withScope($scope, function () use ($user, $roleName): void {
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
    }, $user);
}

/**
 * Helper: make a user an event member with a scoped role.
 */
function makeEventMember(User $user, Event $event, ?string $roleName = null): void
{
    EventUser::factory()->for($event)->for($user)->create();

    if ($roleName) {
        assignEventScopedRole($user, $roleName);
    }
}

describe('viewAny', function () {
    it('allows anyone to view event list', function () {
        $user = User::factory()->create();

        expect($user->can('viewAny', Event::class))->toBeTrue();
    });

    it('allows guests to view event list', function () {
        expect(Gate::forUser(null)->allows('viewAny', Event::class))->toBeTrue();
    });
});

describe('view', function () {
    it('allows anyone to view public approved events', function () {
        $event = Event::factory()->create([
            'visibility' => 'public',
            'status' => Approved::class,
        ]);

        expect(Gate::forUser(null)->allows('view', $event))->toBeTrue();
    });

    it('allows anyone to view unlisted events', function () {
        $event = Event::factory()->create([
            'visibility' => 'unlisted',
        ]);

        expect(Gate::forUser(null)->allows('view', $event))->toBeTrue();
    });

    it('denies guests from viewing private events', function () {
        $event = Event::factory()->create([
            'visibility' => 'private',
        ]);

        expect(Gate::forUser(null)->allows('view', $event))->toBeFalse();
    });

    it('allows event members to view private events', function () {
        $event = Event::factory()->create([
            'visibility' => 'private',
        ]);
        $user = User::factory()->create();

        makeEventMember($user, $event, 'viewer');

        expect($user->can('view', $event))->toBeTrue();
    });

    it('allows submitter to view their own submissions', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'visibility' => 'private',
            'submitter_id' => $user->id,
        ]);

        expect($user->can('view', $event))->toBeTrue();
    });
});

describe('create', function () {
    it('allows any authenticated user to create events', function () {
        $user = User::factory()->create();

        expect($user->can('create', Event::class))->toBeTrue();
    });
});

describe('update', function () {
    it('allows super_admin to update any event', function () {
        $admin = User::factory()->create();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->assignRole('super_admin');
        $event = Event::factory()->create();

        expect($admin->can('update', $event))->toBeTrue();
    });

    it('allows moderator to update any event', function () {
        $moderator = User::factory()->create();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $moderator->assignRole('moderator');
        $event = Event::factory()->create();

        expect($moderator->can('update', $event))->toBeTrue();
    });

    it('allows submitter to update their draft submissions', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'submitter_id' => $user->id,
            'status' => Draft::class,
        ]);

        expect($user->can('update', $event))->toBeTrue();
    });

    it('allows submitter to update their pending submissions', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'submitter_id' => $user->id,
            'status' => Pending::class,
        ]);

        expect($user->can('update', $event))->toBeTrue();
    });

    it('denies submitter from updating approved events', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'submitter_id' => $user->id,
            'status' => Approved::class,
        ]);

        expect($user->can('update', $event))->toBeFalse();
    });

    it('allows event member with event.update permission to update event', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => Approved::class,
        ]);

        makeEventMember($user, $event, 'organizer');

        expect($user->can('update', $event))->toBeTrue();
    });

    it('denies event member without event.update permission from updating event', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => Approved::class,
        ]);

        makeEventMember($user, $event, 'viewer');

        expect($user->can('update', $event))->toBeFalse();
    });

    it('denies random user from updating event', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => Approved::class,
        ]);

        expect($user->can('update', $event))->toBeFalse();
    });
});

describe('delete', function () {
    it('allows super_admin to delete any event', function () {
        $admin = User::factory()->create();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->assignRole('super_admin');
        $event = Event::factory()->create([
            'status' => Approved::class,
        ]);

        expect($admin->can('delete', $event))->toBeTrue();
    });

    it('denies moderator from deleting approved events', function () {
        $moderator = User::factory()->create();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $moderator->assignRole('moderator');
        $event = Event::factory()->create([
            'status' => Approved::class,
        ]);

        expect($moderator->can('delete', $event))->toBeFalse();
    });

    it('allows event member with event.delete permission to delete draft events', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => Draft::class,
        ]);

        makeEventMember($user, $event, 'organizer');

        expect($user->can('delete', $event))->toBeTrue();
    });

    it('denies event member without event.delete permission from deleting draft events', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => Draft::class,
        ]);

        // Member with event.update but not event.delete
        makeEventMember($user, $event, 'editor');

        expect($user->can('delete', $event))->toBeFalse();
    });

    it('denies member with event.delete from deleting approved events', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create([
            'status' => Approved::class,
        ]);

        makeEventMember($user, $event, 'organizer');

        expect($user->can('delete', $event))->toBeFalse();
    });
});

describe('moderate', function () {
    it('allows super_admin to moderate events', function () {
        $admin = User::factory()->create();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->assignRole('super_admin');
        $event = Event::factory()->create();

        expect($admin->can('moderate', $event))->toBeTrue();
    });

    it('allows moderator to moderate events', function () {
        $moderator = User::factory()->create();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $moderator->assignRole('moderator');
        $event = Event::factory()->create();

        expect($moderator->can('moderate', $event))->toBeTrue();
    });

    it('denies regular users from moderating events', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        expect($user->can('moderate', $event))->toBeFalse();
    });
});

describe('manageMembers', function () {
    it('allows super_admin to manage event members', function () {
        $admin = User::factory()->create();
        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin->assignRole('super_admin');
        $event = Event::factory()->create();

        expect($admin->can('manageMembers', $event))->toBeTrue();
    });

    it('allows event member with event.manage-members permission', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        makeEventMember($user, $event, 'organizer');

        expect($user->can('manageMembers', $event))->toBeTrue();
    });

    it('denies event member without event.manage-members permission', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        // Member with update-only role
        makeEventMember($user, $event, 'editor');

        expect($user->can('manageMembers', $event))->toBeFalse();
    });
});

describe('userCanManage helper', function () {
    it('returns true for member with event.update permission in event scope', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        makeEventMember($user, $event, 'organizer');

        expect($event->userCanManage($user))->toBeTrue();
    });

    it('returns false for member without event.update permission', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        EventUser::factory()
            ->for($event)
            ->for($user)
            ->create();

        expect($event->userCanManage($user))->toBeFalse();
    });

    it('returns false for non-member', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();

        expect($event->userCanManage($user))->toBeFalse();
    });
});
