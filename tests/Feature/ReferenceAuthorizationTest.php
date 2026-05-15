<?php

use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Models\Reference;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
use App\Support\Authz\MemberRoleScopes;
use App\Support\Authz\ScopedMemberRoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(ScopedMemberRoleSeeder::class)->ensureForReference();
});

function assignReferenceScopedRole(User $user, string $roleName): void
{
    $scope = app(MemberRoleScopes::class)->reference();

    Authz::withScope($scope, function () use ($user, $roleName): void {
        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
    }, $user);
}

it('requires reference membership even when a user has a shared reference scoped role', function () {
    $referenceWithMembership = Reference::factory()->create();
    $referenceWithoutMembership = Reference::factory()->create();
    $user = User::factory()->create();

    $referenceWithMembership->members()->syncWithoutDetaching([$user->id]);
    assignReferenceScopedRole($user, 'admin');

    $gate = app(MemberPermissionGate::class);

    expect($gate->canReference($user, 'reference.update', $referenceWithMembership))->toBeTrue()
        ->and($gate->canReference($user, 'reference.update', $referenceWithoutMembership))->toBeFalse();
});

it('allows owner members to approve reference updates', function () {
    $reference = Reference::factory()->pending()->create();
    $user = User::factory()->create();

    $reference->members()->syncWithoutDetaching([$user->id]);
    assignReferenceScopedRole($user, 'owner');

    expect($user->can('approve', $reference))->toBeTrue()
        ->and($user->can('manageMembers', $reference))->toBeTrue();
});

it('denies viewer members from approving reference updates', function () {
    $reference = Reference::factory()->pending()->create();
    $user = User::factory()->create();

    $reference->members()->syncWithoutDetaching([$user->id]);
    assignReferenceScopedRole($user, 'viewer');

    expect($user->can('approve', $reference))->toBeFalse()
        ->and($user->can('update', $reference))->toBeFalse();
});
