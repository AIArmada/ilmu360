<?php

use AIArmada\Membership\Enums\MemberRole;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Support\Authz\MemberPermissionGate;
use Illuminate\Support\Facades\DB;
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

it('maps registration permissions to the intended membership roles', function () {
    $event = Event::factory()->create();
    $viewer = User::factory()->create();
    $administrator = User::factory()->create();

    addTestMember($event, $viewer, MemberRole::Viewer);
    addTestMember($event, $administrator, MemberRole::Admin);

    $gate = app(MemberPermissionGate::class);

    expect($gate->canEvent($viewer, 'event.view-registrations', $event))->toBeTrue()
        ->and($gate->canEvent($viewer, 'event.export-registrations', $event))->toBeFalse()
        ->and($gate->canEvent($administrator, 'event.export-registrations', $event))->toBeTrue()
        ->and($gate->eventMembersWithPermission($event, 'event.export-registrations')->modelKeys())
        ->toContain($administrator->getKey())
        ->not->toContain($viewer->getKey());
});

it('uses one pivot-constrained existence query for each membership scope', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $event = Event::factory()->create();
    $person = Person::factory()->create();
    $reference = Reference::factory()->create();

    addTestMember($institution, $user, MemberRole::Viewer);
    addTestMember($event, $user, MemberRole::Viewer);
    addTestMember($person, $user, MemberRole::Viewer);
    addTestMember($reference, $user, MemberRole::Viewer);
    addTestMember(Institution::factory()->create(), $user, MemberRole::Viewer);
    addTestMember(Event::factory()->create(), $user, MemberRole::Viewer);
    addTestMember(Person::factory()->create(), $user, MemberRole::Viewer);
    addTestMember(Reference::factory()->create(), $user, MemberRole::Viewer);

    $gate = app(MemberPermissionGate::class);
    $checks = [
        fn (): bool => $gate->hasAnyInstitutionPermission($user, 'institution.view'),
        fn (): bool => $gate->hasAnyEventPermission($user, 'event.view'),
        fn (): bool => $gate->hasAnyPersonPermission($user, 'speaker.view'),
        fn (): bool => $gate->hasAnyReferencePermission($user, 'reference.view'),
    ];

    foreach ($checks as $check) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $allowed = $check();
        $queries = DB::getQueryLog();

        DB::disableQueryLog();

        expect($allowed)->toBeTrue()
            ->and($queries)->toHaveCount(1)
            ->and(strtolower($queries[0]['query']))->toContain('exists')
            ->toContain('"role" in');
    }
});
