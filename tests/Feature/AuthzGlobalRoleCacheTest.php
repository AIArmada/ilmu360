<?php

use AIArmada\Authz\Models\Role;
use AIArmada\Authz\Support\UserRoleChecker;
use AIArmada\CommerceSupport\Support\FilamentPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('memoizes a global role verdict per user instance and invalidates it on role changes', function (): void {
    $user = User::factory()->create();
    Role::findOrCreate('super_admin', 'web');
    $user->assignRole('super_admin');

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(UserRoleChecker::hasGlobalRole($user, 'super_admin'))->toBeTrue();

    DB::flushQueryLog();

    expect(UserRoleChecker::hasGlobalRole($user, 'super_admin'))->toBeTrue()
        ->and(DB::getQueryLog())->toBeEmpty();

    $user->removeRole('super_admin');

    expect(UserRoleChecker::hasGlobalRole($user, 'super_admin'))->toBeFalse();
});

it('shares the memoized verdict with FilamentPermission', function (): void {
    $user = User::factory()->create();
    Role::findOrCreate('super_admin', 'web');
    $user->assignRole('super_admin');

    Auth::setUser($user);

    expect(FilamentPermission::hasAbility('person.viewAny'))->toBeTrue();

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(FilamentPermission::hasAbility('person.viewAny'))->toBeTrue()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('reflects a role revocation made through another user instance', function (): void {
    $first = User::factory()->create();
    $second = User::query()->findOrFail($first->getKey());
    $role = Role::findOrCreate('super_admin', 'web');

    $first->assignRole($role);

    expect($second->can('anything.at.all'))->toBeTrue();

    $first->removeRole($role);

    expect($second->can('anything.at.all'))->toBeFalse()
        ->and($first->can('anything.at.all'))->toBeFalse();
});
