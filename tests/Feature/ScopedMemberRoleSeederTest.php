<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Permission;
use AIArmada\FilamentAuthz\Models\Role;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('seeds institution scoped member roles when missing', function () {
    Institution::factory()->create();
    $seeder = app(ScopedMemberRoleSeeder::class);
    $scope = app(MemberRoleScopes::class)->institution();

    $seeder->ensureForInstitution();
    $seeder->ensureForInstitution();

    Authz::withScope($scope, function (): void {
        expect(Role::query()->whereIn('name', ['owner', 'admin', 'editor', 'viewer'])->count())->toBe(4);

        $ownerRole = Role::findByName('owner', 'web');

        expect($ownerRole->hasPermissionTo('institution.manage-members'))->toBeTrue()
            ->and($ownerRole->hasPermissionTo('event.manage-members'))->toBeTrue();
    });
});

it('preserves existing scoped role permissions for custom per-scope overrides', function () {
    $seeder = app(ScopedMemberRoleSeeder::class);
    $scope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($scope, function (): void {
        Permission::findOrCreate('institution.view', 'web');
        $role = Role::findOrCreate('owner', 'web');
        $role->syncPermissions(['institution.view']);
    });

    $seeder->ensureForInstitution();

    Authz::withScope($scope, function (): void {
        $ownerRole = Role::findByName('owner', 'web');

        expect($ownerRole->hasPermissionTo('institution.view'))->toBeTrue()
            ->and($ownerRole->hasPermissionTo('institution.manage-members'))->toBeFalse()
            ->and($ownerRole->hasPermissionTo('event.manage-members'))->toBeFalse();
    });
});

it('seeds speaker scoped member roles when missing', function () {
    Speaker::factory()->create();
    $seeder = app(ScopedMemberRoleSeeder::class);
    $scope = app(MemberRoleScopes::class)->speaker();

    $seeder->ensureForSpeaker();

    Authz::withScope($scope, function (): void {
        expect(Role::query()->whereIn('name', ['owner', 'admin', 'editor', 'viewer'])->count())->toBe(4);

        $adminRole = Role::findByName('admin', 'web');

        expect($adminRole->hasPermissionTo('speaker.manage-members'))->toBeTrue();
    });
});

it('seeds event scoped member roles when missing', function () {
    Event::factory()->create();
    $seeder = app(ScopedMemberRoleSeeder::class);
    $scope = app(MemberRoleScopes::class)->event();

    $seeder->ensureForEvent();

    Authz::withScope($scope, function (): void {
        expect(Role::query()->whereIn('name', ['organizer', 'co-organizer', 'editor', 'viewer'])->count())->toBe(4);

        $coOrganizerRole = Role::findByName('co-organizer', 'web');

        expect($coOrganizerRole->hasPermissionTo('event.manage-members'))->toBeTrue()
            ->and($coOrganizerRole->hasPermissionTo('event.delete'))->toBeFalse();
    });
});

it('seeds reference scoped member roles when missing', function () {
    Reference::factory()->create();
    $seeder = app(ScopedMemberRoleSeeder::class);
    $scope = app(MemberRoleScopes::class)->reference();

    $seeder->ensureForReference();

    Authz::withScope($scope, function (): void {
        expect(Role::query()->whereIn('name', ['owner', 'admin', 'editor', 'viewer'])->count())->toBe(4);

        $ownerRole = Role::findByName('owner', 'web');

        expect($ownerRole->hasPermissionTo('reference.manage-members'))->toBeTrue()
            ->and($ownerRole->hasPermissionTo('reference.approve'))->toBeTrue();
    });
});
