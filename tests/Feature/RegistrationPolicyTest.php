<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('allows viewer members to list and view registrations in their institution scope', function () {
    $institution = Institution::factory()->create();
    $event = Event::factory()->create(['institution_id' => $institution->getKey()]);
    $registration = Registration::factory()->for($event)->create();
    $viewer = User::factory()->create();

    addTestMember($institution, $viewer, MemberRole::Viewer);

    expect(Gate::forUser($viewer)->allows('viewAny', Registration::class))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('view', $registration))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $registration))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('export', Registration::class))->toBeFalse();
});

it('allows admins to update and export registrations in their event scope', function () {
    $event = Event::factory()->create();
    $registration = Registration::factory()->for($event)->create();
    $administrator = User::factory()->create();

    addTestMember($event, $administrator, MemberRole::Admin);

    expect(Gate::forUser($administrator)->allows('update', $registration))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('export', Registration::class))->toBeTrue()
        ->and(Gate::forUser($administrator)->allows('exportRegistrations', $event))->toBeTrue();
});

it('denies unrelated users access to registrations', function () {
    $registration = Registration::factory()->for(Event::factory())->create();
    $unrelatedUser = User::factory()->create();

    expect(Gate::forUser($unrelatedUser)->allows('viewAny', Registration::class))->toBeFalse()
        ->and(Gate::forUser($unrelatedUser)->allows('view', $registration))->toBeFalse()
        ->and(Gate::forUser($unrelatedUser)->allows('update', $registration))->toBeFalse()
        ->and(Gate::forUser($unrelatedUser)->allows('export', Registration::class))->toBeFalse();
});
