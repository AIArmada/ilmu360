<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('requires institution membership even when user has shared institution scope role', function () {
    $institutionWithMembership = Institution::factory()->create();
    $institutionWithoutMembership = Institution::factory()->create();
    $user = User::factory()->create();

    app(ScopedMemberRoleSeeder::class)->ensureForInstitution();
    $institutionWithMembership->members()->syncWithoutDetaching([$user->id]);

    $scope = app(MemberRoleScopes::class)->institution();

    Authz::withScope($scope, function () use ($user): void {
        $user->syncRoles(['admin']);
    }, $user);

    $gate = app(MemberPermissionGate::class);

    expect($gate->canInstitution($user, 'institution.update', $institutionWithMembership))->toBeTrue()
        ->and($gate->canInstitution($user, 'institution.update', $institutionWithoutMembership))->toBeFalse();
});

it('requires event membership even when user has shared event scope role', function () {
    $eventWithMembership = Event::factory()->create();
    $eventWithoutMembership = Event::factory()->create();
    $user = User::factory()->create();

    app(ScopedMemberRoleSeeder::class)->ensureForEvent();
    $eventWithMembership->members()->syncWithoutDetaching([$user->id]);

    $scope = app(MemberRoleScopes::class)->event();

    Authz::withScope($scope, function () use ($user): void {
        $user->syncRoles(['organizer']);
    }, $user);

    $gate = app(MemberPermissionGate::class);

    expect($gate->canEvent($user, 'event.update', $eventWithMembership))->toBeTrue()
        ->and($gate->canEvent($user, 'event.update', $eventWithoutMembership))->toBeFalse();
});
