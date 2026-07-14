<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('requires institution membership even when user has shared institution scope role', function () {
    $institutionWithMembership = Institution::factory()->create();
    $institutionWithoutMembership = Institution::factory()->create();
    $user = User::factory()->create();

    addTestMember($institutionWithMembership, $user, MemberRole::Admin);

    $gate = app(MemberPermissionGate::class);

    expect($gate->canInstitution($user, 'institution.update', $institutionWithMembership))->toBeTrue()
        ->and($gate->canInstitution($user, 'institution.update', $institutionWithoutMembership))->toBeFalse();
});

it('requires event membership even when user has shared event scope role', function () {
    $eventWithMembership = Event::factory()->create();
    $eventWithoutMembership = Event::factory()->create();
    $user = User::factory()->create();

    addTestMember($eventWithMembership, $user, MemberRole::Owner);

    $gate = app(MemberPermissionGate::class);

    expect($gate->canEvent($user, 'event.update', $eventWithMembership))->toBeTrue()
        ->and($gate->canEvent($user, 'event.update', $eventWithoutMembership))->toBeFalse();
});
