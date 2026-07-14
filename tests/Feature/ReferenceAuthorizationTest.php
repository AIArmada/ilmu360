<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Models\Reference;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('requires reference membership even when a user has a shared reference scoped role', function () {
    $referenceWithMembership = Reference::factory()->create();
    $referenceWithoutMembership = Reference::factory()->create();
    $user = User::factory()->create();

    addTestMember($referenceWithMembership, $user, MemberRole::Admin);

    $gate = app(MemberPermissionGate::class);

    expect($gate->canReference($user, 'reference.update', $referenceWithMembership))->toBeTrue()
        ->and($gate->canReference($user, 'reference.update', $referenceWithoutMembership))->toBeFalse();
});

it('allows owner members to approve reference updates', function () {
    $reference = Reference::factory()->pending()->create();
    $user = User::factory()->create();

    addTestMember($reference, $user, MemberRole::Owner);

    expect($user->can('approve', $reference))->toBeTrue()
        ->and($user->can('manageMembers', $reference))->toBeTrue();
});

it('denies viewer members from approving reference updates', function () {
    $reference = Reference::factory()->pending()->create();
    $user = User::factory()->create();

    addTestMember($reference, $user, MemberRole::Viewer);

    expect($user->can('approve', $reference))->toBeFalse()
        ->and($user->can('update', $reference))->toBeFalse();
});
